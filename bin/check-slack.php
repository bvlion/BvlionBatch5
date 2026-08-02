<?php

declare(strict_types=1);

use BvlionBatch5\Slack\SlackConnectivityCheckCommand;
use Dotenv\Dotenv;
use GuzzleHttp\Client;

require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

$connectivityCheck = $_ENV['SLACK_CONNECTIVITY_CHECK']
    ?? $_SERVER['SLACK_CONNECTIVITY_CHECK']
    ?? null;
$botToken = $_ENV['SLACK_BOT_TOKEN']
    ?? $_SERVER['SLACK_BOT_TOKEN']
    ?? null;
$channelId = $_ENV['SLACK_TEST_CHANNEL_ID']
    ?? $_SERVER['SLACK_TEST_CHANNEL_ID']
    ?? null;

try {
    (new SlackConnectivityCheckCommand(
        new Client(),
    ))->run($connectivityCheck, $botToken, $channelId);
} catch (RuntimeException $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}
