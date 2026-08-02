<?php

declare(strict_types=1);

use BvlionBatch5\Slack\SlackClient;
use GuzzleHttp\Client;

require_once __DIR__ . '/../vendor/autoload.php';

$configuration = require __DIR__ . '/../bootstrap/config.php';
$channelId = $_ENV['SLACK_TEST_CHANNEL_ID']
    ?? $_SERVER['SLACK_TEST_CHANNEL_ID']
    ?? null;

if (!is_string($channelId) || $channelId === '') {
    throw new RuntimeException('SLACK_TEST_CHANNEL_ID is required.');
}

$slackClient = new SlackClient(
    new Client(),
    $configuration['slack']['bot_token'],
);
$slackClient->postMessage(
    $channelId,
    'Slack API connectivity test.',
);

fwrite(STDOUT, "Slack connectivity check succeeded.\n");
