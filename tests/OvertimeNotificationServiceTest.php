<?php

declare(strict_types=1);

namespace BvlionBatch5\Tests;

use BvlionBatch5\Overtime\OvertimeNotificationRepository;
use BvlionBatch5\Overtime\OvertimeNotificationService;
use BvlionBatch5\Slack\SlackClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class OvertimeNotificationServiceTest extends TestCase
{
    public function testMissingConfigurationDoesNotPostMessage(): void
    {
        $notificationRepository = $this->createStub(
            OvertimeNotificationRepository::class,
        );
        $notificationRepository->method('find')->willReturn(null);
        $requestHistory = [];
        $handlerStack = HandlerStack::create(new MockHandler());
        $handlerStack->push(Middleware::history($requestHistory));
        $service = new OvertimeNotificationService(
            $notificationRepository,
            new SlackClient(
                new Client(['handler' => $handlerStack]),
                'xoxb-example-bot-token',
            ),
        );

        $result = $service->notify();

        self::assertSame(
            [
                'status' => 500,
                'body' => [
                    'message' =>
                        'Overtime notification configuration is missing.',
                ],
            ],
            $result,
        );
        self::assertCount(0, $requestHistory);
    }

    public function testSlackFailureReturnsJsonErrorValues(): void
    {
        $notificationRepository = $this->createStub(
            OvertimeNotificationRepository::class,
        );
        $notificationRepository->method('find')->willReturn([
            'message' => 'Example overtime notification.',
            'channel_id' => 'C0000000000',
        ]);
        $service = new OvertimeNotificationService(
            $notificationRepository,
            new SlackClient(
                new Client([
                    'handler' => new MockHandler([
                        new Response(
                            200,
                            [],
                            '{"ok":false,"error":"channel_not_found"}',
                        ),
                    ]),
                ]),
                'xoxb-example-bot-token',
            ),
        );

        $result = $service->notify();

        self::assertSame(
            [
                'status' => 502,
                'body' => [
                    'message' => 'Slack notification failed.',
                ],
            ],
            $result,
        );
        self::assertStringNotContainsString(
            'Example overtime notification.',
            json_encode($result, JSON_THROW_ON_ERROR),
        );
        self::assertStringNotContainsString(
            'C0000000000',
            json_encode($result, JSON_THROW_ON_ERROR),
        );
    }

    public function testSuccessReturnsTimestampAndPostsConfiguredValues(): void
    {
        $notificationRepository = $this->createStub(
            OvertimeNotificationRepository::class,
        );
        $notificationRepository->method('find')->willReturn([
            'message' => 'Example overtime notification.',
            'channel_id' => 'C0000000000',
        ]);
        $requestHistory = [];
        $mockHandler = new MockHandler([
            new Response(
                200,
                [],
                '{"ok":true,"ts":"1234567890.123456"}',
            ),
        ]);
        $handlerStack = HandlerStack::create($mockHandler);
        $handlerStack->push(Middleware::history($requestHistory));
        $service = new OvertimeNotificationService(
            $notificationRepository,
            new SlackClient(
                new Client(['handler' => $handlerStack]),
                'xoxb-example-bot-token',
            ),
        );

        $result = $service->notify();

        self::assertSame(
            [
                'status' => 200,
                'body' => [
                    'message' => 'Overtime notification sent.',
                    'timestamp' => '1234567890.123456',
                ],
            ],
            $result,
        );
        self::assertCount(1, $requestHistory);
        self::assertSame(
            [
                'channel' => 'C0000000000',
                'text' => 'Example overtime notification.',
                'username' => '麻衣 BOT',
                'icon_url' =>
                    'https://ca.slack-edge.com/T03CDHK90-U03CDM42W-b01e6bc14282-512',
            ],
            json_decode(
                (string) $requestHistory[0]['request']->getBody(),
                true,
                flags: JSON_THROW_ON_ERROR,
            ),
        );
    }
}
