<?php

declare(strict_types=1);

use BvlionBatch5\Database\ConnectionFactory;
use BvlionBatch5\Migration\LegacyDataImporter;
use BvlionBatch5\Migration\LegacyDataVerifier;

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
    $lines = [sprintf('valid: %s', $report['valid'] ? 'true' : 'false')];

    foreach ($report['errors'] as $error) {
        $lines[] = sprintf('error: %s', $error);
    }

    foreach (['dating', 'mail_api'] as $table) {
        if ($report[$table] === null) {
            continue;
        }

        foreach ($report[$table] as $key => $value) {
            $lines[] = sprintf('%s.%s: %s', $table, $key, $value);
        }
    }

    if ($report['overtime'] !== null) {
        foreach ($report['overtime'] as $key => $value) {
            $lines[] = sprintf(
                'overtime.%s: %s',
                $key,
                $value ? 'true' : 'false',
            );
        }
    }

    fwrite(STDOUT, implode("\n", $lines) . "\n");
};

$options = getopt('', ['dating:', 'mail-api:', 'settings:', 'channel-map:']);
$datingPath = $options['dating'] ?? null;
$mailApiPath = $options['mail-api'] ?? null;
$settingsPath = $options['settings'] ?? null;
$channelMapPath = $options['channel-map'] ?? null;

if (
    !is_string($datingPath)
    || !is_string($mailApiPath)
    || !is_string($settingsPath)
    || !is_string($channelMapPath)
) {
    fwrite(
        STDERR,
        "Usage: verify-legacy-migration.php --dating=<path> "
            . "--mail-api=<path> --settings=<path> "
            . "--channel-map=<path>\n",
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
$connectionFactory = new ConnectionFactory(
    $databaseConfiguration['host'],
    $databaseConfiguration['port'],
    $databaseConfiguration['name'],
    $databaseConfiguration['user'],
    $databaseConfiguration['password'],
);
$verifier = new LegacyDataVerifier(
    $connectionFactory,
    new LegacyDataImporter($connectionFactory),
);

$report = $verifier->verify($datingRows, $mailApiRows, $settings, $channelMap);
$printReport($report);

exit(
    $report['valid']
        && ($report['dating']['mismatched_count'] ?? 1) === 0
        && ($report['mail_api']['mismatched_count'] ?? 1) === 0
        && ($report['overtime']['matched'] ?? false) === true
        ? 0
        : 1
);
