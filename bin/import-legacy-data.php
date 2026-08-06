<?php

declare(strict_types=1);

use BvlionBatch5\Database\ConnectionFactory;
use BvlionBatch5\Migration\LegacyDataImporter;

require_once __DIR__ . '/../vendor/autoload.php';

$readJsonFile = static function (string $path): array {
    $contents = file_get_contents($path);

    if ($contents === false) {
        throw new RuntimeException('Input file could not be read.');
    }

    $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

    if (!is_array($decoded)) {
        throw new RuntimeException(
            'Input file does not contain a JSON array or object.',
        );
    }

    return $decoded;
};

$printReport = static function (array $report): void {
    $lines = [
        sprintf('valid: %s', $report['valid'] ? 'true' : 'false'),
        sprintf('dry_run: %s', $report['dry_run'] ? 'true' : 'false'),
        sprintf('executed: %s', $report['executed'] ? 'true' : 'false'),
        sprintf('dating_count: %d', $report['dating_count']),
        sprintf('mail_api_count: %d', $report['mail_api_count']),
        sprintf(
            'mail_api_null_channel_count: %d',
            $report['mail_api_null_channel_count'],
        ),
        sprintf(
            'overtime_present: %s',
            $report['overtime_present'] ? 'true' : 'false',
        ),
        sprintf('dating_inserted: %d', $report['dating_inserted']),
        sprintf('mail_api_inserted: %d', $report['mail_api_inserted']),
        sprintf('overtime_inserted: %d', $report['overtime_inserted']),
    ];

    if ($report['abort_reason'] !== null) {
        $lines[] = sprintf('abort_reason: %s', $report['abort_reason']);
    }

    foreach ($report['warnings'] as $warning) {
        $lines[] = sprintf('warning: %s', $warning);
    }

    foreach ($report['errors'] as $error) {
        $lines[] = sprintf('error: %s', $error);
    }

    fwrite(STDOUT, implode("\n", $lines) . "\n");
};

$options = getopt('', [
    'dating:',
    'mail-api:',
    'settings:',
    'channel-map:',
    'dry-run',
]);
$datingPath = $options['dating'] ?? null;
$mailApiPath = $options['mail-api'] ?? null;
$settingsPath = $options['settings'] ?? null;
$channelMapPath = $options['channel-map'] ?? null;
$dryRun = array_key_exists('dry-run', $options);

if (
    !is_string($datingPath)
    || !is_string($mailApiPath)
    || !is_string($settingsPath)
    || !is_string($channelMapPath)
) {
    fwrite(
        STDERR,
        "Usage: import-legacy-data.php --dating=<path> "
            . "--mail-api=<path> --settings=<path> "
            . "--channel-map=<path> [--dry-run]\n",
    );
    exit(1);
}

try {
    $datingRows = $readJsonFile($datingPath);
    $mailApiRows = $readJsonFile($mailApiPath);
    $settings = $readJsonFile($settingsPath);
    $channelMap = $readJsonFile($channelMapPath);
} catch (Throwable) {
    fwrite(STDERR, "Input files could not be read.\n");
    exit(1);
}

$configuration = require __DIR__ . '/../bootstrap/config.php';
$databaseConfiguration = $configuration['database'];
$importer = new LegacyDataImporter(new ConnectionFactory(
    $databaseConfiguration['host'],
    $databaseConfiguration['port'],
    $databaseConfiguration['name'],
    $databaseConfiguration['user'],
    $databaseConfiguration['password'],
));

$report = $importer->import(
    $datingRows,
    $mailApiRows,
    $settings,
    $channelMap,
    $dryRun,
);
$printReport($report);

exit(
    $report['valid'] && ($dryRun || $report['executed'])
        ? 0
        : 1
);
