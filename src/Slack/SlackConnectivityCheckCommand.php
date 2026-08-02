<?php

declare(strict_types=1);

namespace BvlionBatch5\Slack;

use GuzzleHttp\ClientInterface;
use RuntimeException;

final class SlackConnectivityCheckCommand
{
    public function __construct(
        private ClientInterface $httpClient,
    ) {
    }

    public function run(
        ?string $connectivityCheck,
        ?string $botToken,
        ?string $channelId,
    ): void {
        if ($connectivityCheck !== '1') {
            throw new RuntimeException(
                'SLACK_CONNECTIVITY_CHECK=1 is required.',
            );
        }

        if ($botToken === null || trim($botToken) === '') {
            throw new RuntimeException('SLACK_BOT_TOKEN is required.');
        }

        if ($channelId === null || trim($channelId) === '') {
            throw new RuntimeException(
                'SLACK_TEST_CHANNEL_ID is required.',
            );
        }

        (new SlackClient($this->httpClient, $botToken))->postMessage(
            $channelId,
            'Slack API connectivity test.',
        );

        echo "Slack connectivity check succeeded.\n";
    }
}
