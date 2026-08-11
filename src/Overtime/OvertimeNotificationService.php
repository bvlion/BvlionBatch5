<?php

declare(strict_types=1);

namespace BvlionBatch5\Overtime;

use BvlionBatch5\Slack\SlackClient;
use RuntimeException;

final class OvertimeNotificationService
{
    public function __construct(
        private OvertimeNotificationRepository $notificationRepository,
        private SlackClient $slackClient,
    ) {
    }

    /**
     * @return array{
     *     status: int,
     *     body: array{message: string, timestamp?: string}
     * }
     */
    public function notify(): array
    {
        $notificationConfiguration = $this->notificationRepository->find();

        if ($notificationConfiguration === null) {
            return [
                'status' => 500,
                'body' => [
                    'message' =>
                        'Overtime notification configuration is missing.',
                ],
            ];
        }

        try {
            $timestamp = $this->slackClient->postCustomMessage(
                $notificationConfiguration['channel_id'],
                $notificationConfiguration['message'],
                '麻衣 BOT',
                'https://ca.slack-edge.com/T03CDHK90-U03CDM42W-b01e6bc14282-512',
            );
        } catch (RuntimeException) {
            return [
                'status' => 502,
                'body' => [
                    'message' => 'Slack notification failed.',
                ],
            ];
        }

        return [
            'status' => 200,
            'body' => [
                'message' => 'Overtime notification sent.',
                'timestamp' => $timestamp,
            ],
        ];
    }
}
