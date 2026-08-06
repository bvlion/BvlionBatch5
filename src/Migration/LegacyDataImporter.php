<?php

declare(strict_types=1);

namespace BvlionBatch5\Migration;

use BvlionBatch5\Database\ConnectionFactory;
use Throwable;

final class LegacyDataImporter
{
    public function __construct(
        private ConnectionFactory $connectionFactory,
    ) {
    }

    /**
     * Validates the legacy export files against the new schema and
     * resolves legacy Slack channel names to new channel IDs. No
     * database is touched.
     *
     * @param list<array<string, mixed>> $datingRows
     * @param list<array<string, mixed>> $mailApiRows
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $channelMap
     * @return array{
     *     errors: list<string>,
     *     warnings: list<string>,
     *     dating: list<array{
     *         id: int,
     *         target_date: string,
     *         message: string,
     *         channel_id: string|null
     *     }>,
     *     mail_api: list<array{
     *         id: int,
     *         target_from: string,
     *         to_folder: string,
     *         channel_id: string|null,
     *         enable_flag: int
     *     }>,
     *     mail_api_null_channel_count: int,
     *     overtime: array{message: string, channel_id: string}|null
     * }
     */
    public function resolve(
        array $datingRows,
        array $mailApiRows,
        array $settings,
        array $channelMap,
    ): array {
        $errors = [];
        $usedChannelNames = [];

        [
            $datingChannelId,
            $overtimeMessage,
            $overtimeChannelId,
        ] = $this->resolveSettings(
            $settings,
            $channelMap,
            $usedChannelNames,
            $errors,
        );

        $resolvedDating = $this->resolveDatingRows(
            $datingRows,
            $datingChannelId,
            $errors,
        );

        [$resolvedMailApi, $nullChannelCount] = $this->resolveMailApiRows(
            $mailApiRows,
            $channelMap,
            $usedChannelNames,
            $errors,
        );

        $unusedChannelCount = 0;

        foreach (array_keys($channelMap) as $channelName) {
            if (!isset($usedChannelNames[$channelName])) {
                $unusedChannelCount++;
            }
        }

        $warnings = [];

        if ($unusedChannelCount > 0) {
            $warnings[] = sprintf(
                'channel_map.json contains %d unused entr%s.',
                $unusedChannelCount,
                $unusedChannelCount === 1 ? 'y' : 'ies',
            );
        }

        $overtime = $overtimeChannelId !== null && $overtimeMessage !== null
            ? ['message' => $overtimeMessage, 'channel_id' => $overtimeChannelId]
            : null;

        usort(
            $resolvedDating,
            static fn (array $a, array $b): int => $a['id'] <=> $b['id'],
        );
        usort(
            $resolvedMailApi,
            static fn (array $a, array $b): int => $a['id'] <=> $b['id'],
        );

        return [
            'errors' => $errors,
            'warnings' => $warnings,
            'dating' => $resolvedDating,
            'mail_api' => $resolvedMailApi,
            'mail_api_null_channel_count' => $nullChannelCount,
            'overtime' => $overtime,
        ];
    }

    /**
     * Validates, and unless dry-run, imports the legacy data into the
     * new schema. The target tables must all be empty; otherwise the
     * run is aborted without writing anything. All inserts happen in
     * a single transaction, which is rolled back on any failure.
     *
     * @param list<array<string, mixed>> $datingRows
     * @param list<array<string, mixed>> $mailApiRows
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $channelMap
     * @return array{
     *     valid: bool,
     *     errors: list<string>,
     *     warnings: list<string>,
     *     dry_run: bool,
     *     executed: bool,
     *     dating_count: int,
     *     mail_api_count: int,
     *     mail_api_null_channel_count: int,
     *     overtime_present: bool,
     *     abort_reason: string|null,
     *     dating_inserted: int,
     *     mail_api_inserted: int,
     *     overtime_inserted: int
     * }
     */
    public function import(
        array $datingRows,
        array $mailApiRows,
        array $settings,
        array $channelMap,
        bool $dryRun,
    ): array {
        $resolved = $this->resolve(
            $datingRows,
            $mailApiRows,
            $settings,
            $channelMap,
        );

        $report = [
            'valid' => $resolved['errors'] === [],
            'errors' => $resolved['errors'],
            'warnings' => $resolved['warnings'],
            'dry_run' => $dryRun,
            'executed' => false,
            'dating_count' => count($resolved['dating']),
            'mail_api_count' => count($resolved['mail_api']),
            'mail_api_null_channel_count' =>
                $resolved['mail_api_null_channel_count'],
            'overtime_present' => $resolved['overtime'] !== null,
            'abort_reason' => null,
            'dating_inserted' => 0,
            'mail_api_inserted' => 0,
            'overtime_inserted' => 0,
        ];

        if (!$report['valid'] || $dryRun) {
            return $report;
        }

        $connection = $this->connectionFactory->create();
        $existingCounts = [
            'dating' => (int) $connection
                ->query('SELECT COUNT(*) FROM dating')
                ->fetchColumn(),
            'mail_api' => (int) $connection
                ->query('SELECT COUNT(*) FROM mail_api')
                ->fetchColumn(),
            'overtime_notification_settings' => (int) $connection
                ->query('SELECT COUNT(*) FROM overtime_notification_settings')
                ->fetchColumn(),
        ];

        foreach ($existingCounts as $table => $count) {
            if ($count > 0) {
                $report['abort_reason'] = sprintf(
                    '%s is not empty.',
                    $table,
                );

                return $report;
            }
        }

        $connection->beginTransaction();

        try {
            $datingStatement = $connection->prepare(
                <<<'SQL'
                    INSERT INTO dating (
                        id, target_date, message, channel_id
                    ) VALUES (
                        :id, :target_date, :message, :channel_id
                    )
                    SQL,
            );

            foreach ($resolved['dating'] as $row) {
                $datingStatement->execute($row);
                $report['dating_inserted']++;
            }

            $mailApiStatement = $connection->prepare(
                <<<'SQL'
                    INSERT INTO mail_api (
                        id, target_from, to_folder, channel_id, enable_flag
                    ) VALUES (
                        :id, :target_from, :to_folder, :channel_id, :enable_flag
                    )
                    SQL,
            );

            foreach ($resolved['mail_api'] as $row) {
                $mailApiStatement->execute($row);
                $report['mail_api_inserted']++;
            }

            $overtimeStatement = $connection->prepare(
                <<<'SQL'
                    INSERT INTO overtime_notification_settings (
                        id, message, channel_id
                    ) VALUES (
                        1, :message, :channel_id
                    )
                    SQL,
            );
            $overtimeStatement->execute($resolved['overtime']);
            $report['overtime_inserted'] = 1;

            $connection->commit();
            $report['executed'] = true;
        } catch (Throwable) {
            $connection->rollBack();
            $report['valid'] = false;
            $report['dating_inserted'] = 0;
            $report['mail_api_inserted'] = 0;
            $report['overtime_inserted'] = 0;
            $report['abort_reason'] =
                'Import transaction failed and was rolled back.';
        }

        return $report;
    }

    /**
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $channelMap
     * @param array<string, true> $usedChannelNames
     * @param list<string> $errors
     * @return array{0: string|null, 1: string|null, 2: string|null}
     */
    private function resolveSettings(
        array $settings,
        array $channelMap,
        array &$usedChannelNames,
        array &$errors,
    ): array {
        $datingChannelName = $settings['dating_channel'] ?? null;
        $overtimeMessage = $settings['overtime_message'] ?? null;
        $overtimeChannelName = $settings['overtime_channel'] ?? null;

        if (!is_string($datingChannelName) || $datingChannelName === '') {
            $errors[] = 'migration-settings.json: dating_channel must be '
                . 'a non-empty string.';
            $datingChannelName = null;
        }

        if (!is_string($overtimeMessage) || $overtimeMessage === '') {
            $errors[] = 'migration-settings.json: overtime_message must '
                . 'be a non-empty string.';
            $overtimeMessage = null;
        }

        if (!is_string($overtimeChannelName) || $overtimeChannelName === '') {
            $errors[] = 'migration-settings.json: overtime_channel must '
                . 'be a non-empty string.';
            $overtimeChannelName = null;
        }

        $datingChannelId = $this->resolveChannelId(
            $datingChannelName,
            $channelMap,
            $usedChannelNames,
            $errors,
            'migration-settings.json: dating_channel is not mapped to '
                . 'a channel ID.',
        );
        $overtimeChannelId = $this->resolveChannelId(
            $overtimeChannelName,
            $channelMap,
            $usedChannelNames,
            $errors,
            'migration-settings.json: overtime_channel is not mapped to '
                . 'a channel ID.',
        );

        return [$datingChannelId, $overtimeMessage, $overtimeChannelId];
    }

    /**
     * @param array<string, mixed> $channelMap
     * @param array<string, true> $usedChannelNames
     * @param list<string> $errors
     */
    private function resolveChannelId(
        ?string $channelName,
        array $channelMap,
        array &$usedChannelNames,
        array &$errors,
        string $unmappedErrorMessage,
    ): ?string {
        if ($channelName === null) {
            return null;
        }

        $usedChannelNames[$channelName] = true;

        if (!array_key_exists($channelName, $channelMap)) {
            $errors[] = $unmappedErrorMessage;

            return null;
        }

        $channelId = $channelMap[$channelName];

        return is_string($channelId) ? $channelId : null;
    }

    /**
     * @param list<array<string, mixed>> $datingRows
     * @param list<string> $errors
     * @return list<array{
     *     id: int,
     *     target_date: string,
     *     message: string,
     *     channel_id: string|null
     * }>
     */
    private function resolveDatingRows(
        array $datingRows,
        ?string $channelId,
        array &$errors,
    ): array {
        $resolved = [];
        $seenIds = [];

        foreach ($datingRows as $index => $row) {
            $id = $row['id'] ?? null;
            $targetDate = $row['target_date'] ?? null;
            $message = $row['message'] ?? null;

            if (!is_int($id) || $id <= 0) {
                $errors[] = sprintf(
                    'dating.json[%d]: id must be a positive integer.',
                    $index,
                );

                continue;
            }

            if (isset($seenIds[$id])) {
                $errors[] = sprintf(
                    'dating.json: id %d is duplicated.',
                    $id,
                );

                continue;
            }

            $seenIds[$id] = true;

            if (
                !is_string($targetDate)
                || $targetDate === ''
                || strlen($targetDate) > 8
            ) {
                $errors[] = sprintf(
                    'dating.json id %d: target_date must be a non-empty '
                        . 'string of at most 8 characters.',
                    $id,
                );

                continue;
            }

            if (!is_string($message) || $message === '') {
                $errors[] = sprintf(
                    'dating.json id %d: message must be a non-empty '
                        . 'string.',
                    $id,
                );

                continue;
            }

            $resolved[] = [
                'id' => $id,
                'target_date' => $targetDate,
                'message' => $message,
                'channel_id' => $channelId,
            ];
        }

        return $resolved;
    }

    /**
     * @param list<array<string, mixed>> $mailApiRows
     * @param array<string, mixed> $channelMap
     * @param array<string, true> $usedChannelNames
     * @param list<string> $errors
     * @return array{
     *     0: list<array{
     *         id: int,
     *         target_from: string,
     *         to_folder: string,
     *         channel_id: string|null,
     *         enable_flag: int
     *     }>,
     *     1: int
     * }
     */
    private function resolveMailApiRows(
        array $mailApiRows,
        array $channelMap,
        array &$usedChannelNames,
        array &$errors,
    ): array {
        $resolved = [];
        $seenIds = [];
        $nullChannelCount = 0;

        foreach ($mailApiRows as $index => $row) {
            $id = $row['id'] ?? null;
            $targetFrom = $row['target_from'] ?? null;
            $toFolder = $row['to_folder'] ?? null;
            $channel = $row['channel'] ?? null;
            $userName = $row['user_name'] ?? null;
            $iconUrl = $row['icon_url'] ?? null;
            $prefixFormat = $row['prefix_format'] ?? null;
            $enableFlag = $row['enable_flag'] ?? null;

            if (!is_int($id) || $id <= 0) {
                $errors[] = sprintf(
                    'mail_api.json[%d]: id must be a positive integer.',
                    $index,
                );

                continue;
            }

            if (isset($seenIds[$id])) {
                $errors[] = sprintf(
                    'mail_api.json: id %d is duplicated.',
                    $id,
                );

                continue;
            }

            $seenIds[$id] = true;

            if (
                !is_string($targetFrom)
                || $targetFrom === ''
                || strlen($targetFrom) > 255
            ) {
                $errors[] = sprintf(
                    'mail_api.json id %d: target_from must be a '
                        . 'non-empty string of at most 255 characters.',
                    $id,
                );

                continue;
            }

            if (
                !is_string($toFolder)
                || $toFolder === ''
                || strlen($toFolder) > 255
            ) {
                $errors[] = sprintf(
                    'mail_api.json id %d: to_folder must be a non-empty '
                        . 'string of at most 255 characters.',
                    $id,
                );

                continue;
            }

            if ($enableFlag !== 0 && $enableFlag !== 1) {
                $errors[] = sprintf(
                    'mail_api.json id %d: enable_flag must be 0 or 1.',
                    $id,
                );

                continue;
            }

            if (
                ($channel !== null && !is_string($channel))
                || ($userName !== null && !is_string($userName))
                || ($iconUrl !== null && !is_string($iconUrl))
                || ($prefixFormat !== null && !is_string($prefixFormat))
            ) {
                $errors[] = sprintf(
                    'mail_api.json id %d: channel, user_name, icon_url '
                        . 'and prefix_format must each be a string or '
                        . 'null.',
                    $id,
                );

                continue;
            }

            $channelId = null;

            if (
                $channel !== null
                && $userName !== null
                && $iconUrl !== null
                && $prefixFormat !== null
            ) {
                $channelId = $this->resolveChannelId(
                    $channel,
                    $channelMap,
                    $usedChannelNames,
                    $errors,
                    sprintf(
                        'channel_map.json: mail_api.json id %d '
                            . 'references an unmapped channel name.',
                        $id,
                    ),
                );

                if ($channelId === null) {
                    continue;
                }
            } else {
                $nullChannelCount++;
            }

            $resolved[] = [
                'id' => $id,
                'target_from' => $targetFrom,
                'to_folder' => $toFolder,
                'channel_id' => $channelId,
                'enable_flag' => $enableFlag,
            ];
        }

        return [$resolved, $nullChannelCount];
    }
}
