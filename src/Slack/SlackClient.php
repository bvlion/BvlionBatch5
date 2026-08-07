<?php

declare(strict_types=1);

namespace BvlionBatch5\Slack;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use JsonException;
use RuntimeException;

final class SlackClient
{
    private const ENDPOINT = 'https://slack.com/api/chat.postMessage';
    private const TIMEOUT_SECONDS = 10.0;

    public function __construct(
        private ClientInterface $httpClient,
        private string $botToken,
    ) {
    }

    public function postMessage(string $channelId, string $message): string
    {
        return $this->send([
            'channel' => $channelId,
            'text' => $message,
        ]);
    }

    /**
     * Same as postMessage(), but overrides the Bot's display name and
     * icon for this single post via chat:write.customize. Used only
     * by mail processing, which identifies each forwarded mail's
     * original sender this way; dating and overtime notifications
     * keep using postMessage() and always show the Bot's own name
     * and icon.
     */
    public function postCustomMessage(
        string $channelId,
        string $message,
        string $username,
        string $iconUrl,
    ): string {
        return $this->send([
            'channel' => $channelId,
            'text' => $message,
            'username' => $username,
            'icon_url' => $iconUrl,
        ]);
    }

    /**
     * @param array<string, string> $payload
     */
    private function send(array $payload): string
    {
        try {
            $response = $this->httpClient->request(
                'POST',
                self::ENDPOINT,
                [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $this->botToken,
                    ],
                    'json' => $payload,
                    'http_errors' => true,
                    'timeout' => self::TIMEOUT_SECONDS,
                ],
            );
        } catch (GuzzleException) {
            throw new RuntimeException('Slack request failed.');
        }

        try {
            $responsePayload = json_decode(
                (string) $response->getBody(),
                true,
                flags: JSON_THROW_ON_ERROR,
            );
        } catch (JsonException) {
            throw new RuntimeException('Slack API response was invalid.');
        }

        if (
            !is_array($responsePayload)
            || ($responsePayload['ok'] ?? false) !== true
        ) {
            throw new RuntimeException('Slack API request failed.');
        }

        $timestamp = $responsePayload['ts'] ?? null;

        if (!is_string($timestamp) || $timestamp === '') {
            throw new RuntimeException('Slack API response was invalid.');
        }

        return $timestamp;
    }
}
