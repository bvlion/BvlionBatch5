<?php

declare(strict_types=1);

use BvlionBatch5\Mail\ImapMailbox;
use Dotenv\Dotenv;

require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();
$dotenv->required([
    'IMAP_HOST',
    'IMAP_PORT',
    'IMAP_USERNAME',
    'IMAP_PASSWORD',
])->notEmpty();

$mailbox = new ImapMailbox(
    $_ENV['IMAP_HOST'] ?? $_SERVER['IMAP_HOST'],
    (int) ($_ENV['IMAP_PORT'] ?? $_SERVER['IMAP_PORT']),
    $_ENV['IMAP_USERNAME'] ?? $_SERVER['IMAP_USERNAME'],
    $_ENV['IMAP_PASSWORD'] ?? $_SERVER['IMAP_PASSWORD'],
);

try {
    $mailbox->connect();
    $mailbox->getUidValidity();
    $mailbox->searchMessages();
    fwrite(STDOUT, "IMAP connectivity check succeeded.\n");
} catch (RuntimeException $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
} finally {
    $mailbox->disconnect();
}
