<?php

declare(strict_types=1);

namespace BvlionBatch5\Tests;

use BvlionBatch5\Database\ConnectionFactory;
use BvlionBatch5\Migration\LegacyDataImporter;
use PDO;
use PHPUnit\Framework\TestCase;

final class LegacyDataImporterTest extends TestCase
{
    private PDO $connection;
    private ConnectionFactory $connectionFactory;

    protected function setUp(): void
    {
        $databaseHost = $_ENV['TEST_DB_HOST']
            ?? $_SERVER['TEST_DB_HOST']
            ?? 'database';
        $this->connectionFactory = new ConnectionFactory(
            $databaseHost,
            '3306',
            'example_database',
            'example_database_user',
            'example_database_password',
        );
        $this->connection = $this->connectionFactory->create();
        $this->clearTargetTables();
    }

    protected function tearDown(): void
    {
        if (!isset($this->connection)) {
            return;
        }

        $this->clearTargetTables();
    }

    private function clearTargetTables(): void
    {
        $this->connection->exec('DELETE FROM mail_api');
        $this->connection->exec('DELETE FROM dating');
        $this->connection->exec('DELETE FROM overtime_notification_settings');
    }

    /**
     * @return list<array{id: int, target_date: string, message: string}>
     */
    private function exampleDatingRows(): array
    {
        return [
            ['id' => 1, 'target_date' => '0411', 'message' => 'Example annual.'],
            ['id' => 2, 'target_date' => '20260101', 'message' => 'Example: %s'],
        ];
    }

    /**
     * @return list<array{
     *     id: int,
     *     target_from: string,
     *     to_folder: string,
     *     channel: string|null,
     *     user_name: string|null,
     *     icon_url: string|null,
     *     prefix_format: string|null,
     *     enable_flag: int
     * }>
     */
    private function exampleMailApiRows(): array
    {
        return [
            [
                'id' => 40,
                'target_from' => 'example-sender-a',
                'to_folder' => 'ExampleArchiveA',
                'channel' => 'example-legacy-channel',
                'user_name' => 'Example Bot',
                'icon_url' => 'https://example.test/icon.png',
                'prefix_format' => '',
                'enable_flag' => 1,
            ],
            [
                'id' => 41,
                'target_from' => 'example-sender-b',
                'to_folder' => 'ExampleArchiveB',
                'channel' => null,
                'user_name' => null,
                'icon_url' => null,
                'prefix_format' => null,
                'enable_flag' => 1,
            ],
        ];
    }

    /**
     * @return array{
     *     dating_channel: string,
     *     overtime_message: string,
     *     overtime_channel: string
     * }
     */
    private function exampleSettings(): array
    {
        return [
            'dating_channel' => 'example-legacy-channel',
            'overtime_message' => 'Example overtime message.',
            'overtime_channel' => 'example-legacy-channel',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function exampleChannelMap(): array
    {
        return ['example-legacy-channel' => 'C0000000000'];
    }

    public function testDryRunValidatesWithoutWritingToDatabase(): void
    {
        $importer = new LegacyDataImporter($this->connectionFactory);

        $report = $importer->import(
            $this->exampleDatingRows(),
            $this->exampleMailApiRows(),
            $this->exampleSettings(),
            $this->exampleChannelMap(),
            true,
        );

        self::assertTrue($report['valid']);
        self::assertTrue($report['dry_run']);
        self::assertFalse($report['executed']);
        self::assertSame(2, $report['dating_count']);
        self::assertSame(2, $report['mail_api_count']);
        self::assertSame(1, $report['mail_api_null_channel_count']);
        self::assertTrue($report['overtime_present']);
        self::assertSame(
            0,
            (int) $this->connection
                ->query('SELECT COUNT(*) FROM dating')
                ->fetchColumn(),
        );
        self::assertSame(
            0,
            (int) $this->connection
                ->query('SELECT COUNT(*) FROM mail_api')
                ->fetchColumn(),
        );
    }

    public function testImportsAllTablesInSingleTransaction(): void
    {
        $importer = new LegacyDataImporter($this->connectionFactory);

        $report = $importer->import(
            $this->exampleDatingRows(),
            $this->exampleMailApiRows(),
            $this->exampleSettings(),
            $this->exampleChannelMap(),
            false,
        );

        self::assertTrue($report['valid']);
        self::assertTrue($report['executed']);
        self::assertSame(2, $report['dating_inserted']);
        self::assertSame(2, $report['mail_api_inserted']);
        self::assertSame(1, $report['overtime_inserted']);

        $datingChannelIds = $this->connection
            ->query('SELECT channel_id FROM dating ORDER BY id')
            ->fetchAll(PDO::FETCH_COLUMN);
        self::assertSame(['C0000000000', 'C0000000000'], $datingChannelIds);

        $mailApiChannelIds = $this->connection
            ->query('SELECT channel_id FROM mail_api ORDER BY id')
            ->fetchAll(PDO::FETCH_COLUMN);
        self::assertSame(['C0000000000', null], $mailApiChannelIds);
    }

    public function testAbortsWhenTargetTableIsNotEmpty(): void
    {
        $this->connection->exec(
            "INSERT INTO dating (target_date, message, channel_id) "
                . "VALUES ('0101', 'Existing.', 'C0000000000')",
        );

        $importer = new LegacyDataImporter($this->connectionFactory);
        $report = $importer->import(
            $this->exampleDatingRows(),
            $this->exampleMailApiRows(),
            $this->exampleSettings(),
            $this->exampleChannelMap(),
            false,
        );

        self::assertTrue($report['valid']);
        self::assertFalse($report['executed']);
        self::assertSame('dating is not empty.', $report['abort_reason']);
        self::assertSame(
            0,
            (int) $this->connection
                ->query('SELECT COUNT(*) FROM mail_api')
                ->fetchColumn(),
        );
        self::assertSame(
            0,
            (int) $this->connection
                ->query('SELECT COUNT(*) FROM overtime_notification_settings')
                ->fetchColumn(),
        );
    }

    public function testAbortsWhenChannelMapIsMissingAnEntry(): void
    {
        $importer = new LegacyDataImporter($this->connectionFactory);
        $report = $importer->import(
            $this->exampleDatingRows(),
            $this->exampleMailApiRows(),
            $this->exampleSettings(),
            [],
            false,
        );

        self::assertFalse($report['valid']);
        self::assertFalse($report['executed']);
        self::assertNotEmpty($report['errors']);
        self::assertSame(
            0,
            (int) $this->connection
                ->query('SELECT COUNT(*) FROM dating')
                ->fetchColumn(),
        );
    }

    public function testTransactionRollsBackWhenAnInsertFails(): void
    {
        // dating (inserted first) resolves to a valid channel ID, while
        // the mail_api row (inserted afterward, in the same transaction)
        // resolves to an oversized channel ID that MySQL rejects. This
        // proves that already-inserted rows are rolled back together
        // with the row that caused the failure.
        $importer = new LegacyDataImporter($this->connectionFactory);
        $mailApiRows = [
            [
                'id' => 40,
                'target_from' => 'example-sender-a',
                'to_folder' => 'ExampleArchiveA',
                'channel' => 'example-legacy-channel-b',
                'user_name' => 'Example Bot',
                'icon_url' => 'https://example.test/icon.png',
                'prefix_format' => '',
                'enable_flag' => 1,
            ],
        ];
        $channelMap = [
            'example-legacy-channel' => 'C0000000000',
            'example-legacy-channel-b' => str_repeat('C', 256),
        ];

        $report = $importer->import(
            $this->exampleDatingRows(),
            $mailApiRows,
            $this->exampleSettings(),
            $channelMap,
            false,
        );

        self::assertFalse($report['valid']);
        self::assertFalse($report['executed']);
        self::assertSame(0, $report['dating_inserted']);
        self::assertSame(0, $report['mail_api_inserted']);
        self::assertSame(0, $report['overtime_inserted']);
        self::assertSame(
            0,
            (int) $this->connection
                ->query('SELECT COUNT(*) FROM dating')
                ->fetchColumn(),
        );
        self::assertSame(
            0,
            (int) $this->connection
                ->query('SELECT COUNT(*) FROM mail_api')
                ->fetchColumn(),
        );
    }
}
