<?php

declare(strict_types=1);

use BvlionBatch5\Database\ConnectionFactory;
use BvlionBatch5\Migration\LegacyJsonFileWriter;
use Dotenv\Dotenv;

require_once __DIR__ . '/../vendor/autoload.php';

const LEGACY_EXPORT_QUERIES = [
    'dating' => <<<'SQL'
        SELECT
            pk AS id,
            target_date,
            message
        FROM dating
        ORDER BY pk
        SQL,
    'mail_api' => <<<'SQL'
        SELECT
            pk AS id,
            target_from,
            to_folder,
            channel,
            user_name,
            icon_url,
            prefix_format,
            enable_flag
        FROM mail_api
        ORDER BY pk
        SQL,
];

$options = getopt('', ['env-file:', 'table:', 'output:']);
$envFile = $options['env-file'] ?? null;
$table = $options['table'] ?? null;
$outputPath = $options['output'] ?? null;

if (
    !is_string($envFile)
    || !is_string($table)
    || !is_string($outputPath)
    || !array_key_exists($table, LEGACY_EXPORT_QUERIES)
) {
    fwrite(
        STDERR,
        "Usage: export-legacy-data.php --env-file=<path> "
            . "--table=<dating|mail_api> --output=<path>\n",
    );
    exit(1);
}

try {
    $dotenv = Dotenv::createImmutable(
        dirname($envFile),
        basename($envFile),
    );
    $dotenv->safeLoad();
    $dotenv->required([
        'LEGACY_DB_HOST',
        'LEGACY_DB_PORT',
        'LEGACY_DB_NAME',
        'LEGACY_DB_USER',
        'LEGACY_DB_PASSWORD',
    ])->notEmpty();
} catch (Throwable) {
    fwrite(
        STDERR,
        "Legacy database environment file could not be loaded.\n",
    );
    exit(1);
}

try {
    $connection = (new ConnectionFactory(
        $_ENV['LEGACY_DB_HOST'],
        $_ENV['LEGACY_DB_PORT'],
        $_ENV['LEGACY_DB_NAME'],
        $_ENV['LEGACY_DB_USER'],
        $_ENV['LEGACY_DB_PASSWORD'],
    ))->create();

    $rows = $connection
        ->query(LEGACY_EXPORT_QUERIES[$table])
        ->fetchAll(PDO::FETCH_ASSOC);
    $rowCount = (new LegacyJsonFileWriter())->write($rows, $outputPath);
} catch (Throwable) {
    fwrite(STDERR, sprintf("Legacy export failed: %s.\n", $table));
    exit(1);
}

fwrite(STDOUT, sprintf(
    "Exported %d row(s) for %s to %s\n",
    $rowCount,
    $table,
    $outputPath,
));
