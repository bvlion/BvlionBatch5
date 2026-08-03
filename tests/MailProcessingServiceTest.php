<?php

declare(strict_types=1);

namespace BvlionBatch5\Tests;

use BvlionBatch5\Mail\ImapMailbox;
use BvlionBatch5\Mail\MailProcessingHistoryRepository;
use BvlionBatch5\Mail\MailProcessingService;
use BvlionBatch5\Mail\MailRuleRepository;
use BvlionBatch5\Mail\MimeMessageDecoder;
use BvlionBatch5\Slack\SlackClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class MailProcessingServiceTest extends TestCase
{
    public function testSuccessfulMessageIsPostedSeenMovedAndCompleted(): void
    {
        $mailRuleRepository = $this->createStub(MailRuleRepository::class);
        $mailRuleRepository->method('findEnabledRules')->willReturn([
            [
                'target_from' => 'EXAMPLE-SENDER',
                'to_folder' => 'ExampleArchive',
                'channel_id' => 'C0000000000',
            ],
        ]);
        $mailbox = $this->createMock(ImapMailbox::class);
        $mailbox
            ->expects(self::once())
            ->method('connect')
            ->with(false, false);
        $mailbox
            ->expects(self::once())
            ->method('getUidValidity')
            ->willReturn(123456);
        $mailbox
            ->expects(self::once())
            ->method('searchMessages')
            ->willReturn([
                [
                    'uid' => 101,
                    'sender' => 'example-sender@example.test',
                    'subject' => 'Example received message.',
                ],
            ]);
        $mailbox
            ->expects(self::once())
            ->method('readMessage')
            ->with(101, self::isInstanceOf(MimeMessageDecoder::class))
            ->willReturn([
                'subject' => 'Example subject.',
                'body' => 'Example body.',
            ]);
        $mailbox
            ->expects(self::once())
            ->method('markMessageAsSeen')
            ->with(101);
        $mailbox
            ->expects(self::once())
            ->method('moveMessage')
            ->with(101, 'ExampleArchive');
        $mailbox->expects(self::once())->method('disconnect');
        $historyRepository = $this->createMock(
            MailProcessingHistoryRepository::class,
        );
        $historyRepository
            ->expects(self::once())
            ->method('find')
            ->with('example-mailbox', 123456, 101)
            ->willReturn(null);
        $historyRepository
            ->expects(self::once())
            ->method('recordSlackPosted')
            ->with(
                'example-mailbox',
                123456,
                101,
                '1234567890.123456',
            );
        $historyRepository
            ->expects(self::once())
            ->method('markCompleted')
            ->with('example-mailbox', 123456, 101);
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
        $service = new MailProcessingService(
            $mailRuleRepository,
            $mailbox,
            new MimeMessageDecoder(),
            $historyRepository,
            new SlackClient(
                new Client(['handler' => $handlerStack]),
                'xoxb-example-bot-token',
            ),
            'example-mailbox',
        );

        $result = $service->process();

        self::assertSame(
            ['success' => true, 'failure_count' => 0],
            $result,
        );
        self::assertCount(1, $requestHistory);
        self::assertSame(
            [
                'channel' => 'C0000000000',
                'text' => "件名：Example subject.\n"
                    . "----------\nExample body.\n----------",
            ],
            json_decode(
                (string) $requestHistory[0]['request']->getBody(),
                true,
                flags: JSON_THROW_ON_ERROR,
            ),
        );
    }

    public function testNoEnabledRuleDoesNotConnectOrPost(): void
    {
        $mailRuleRepository = $this->createStub(MailRuleRepository::class);
        $mailRuleRepository->method('findEnabledRules')->willReturn([]);
        $mailbox = $this->createMock(ImapMailbox::class);
        $mailbox->expects(self::never())->method('connect');
        $historyRepository = $this->createStub(
            MailProcessingHistoryRepository::class,
        );
        $requestHistory = [];
        $handlerStack = HandlerStack::create(new MockHandler());
        $handlerStack->push(Middleware::history($requestHistory));
        $service = new MailProcessingService(
            $mailRuleRepository,
            $mailbox,
            new MimeMessageDecoder(),
            $historyRepository,
            new SlackClient(
                new Client(['handler' => $handlerStack]),
                'xoxb-example-bot-token',
            ),
            'example-mailbox',
        );

        self::assertSame(
            ['success' => true, 'failure_count' => 0],
            $service->process(),
        );
        self::assertCount(0, $requestHistory);
    }

    public function testNoTargetMessageReturnsSuccessWithoutPosting(): void
    {
        $mailRuleRepository = $this->createStub(MailRuleRepository::class);
        $mailRuleRepository->method('findEnabledRules')->willReturn([
            [
                'target_from' => 'example-sender',
                'to_folder' => 'ExampleArchive',
                'channel_id' => 'C0000000000',
            ],
        ]);
        $mailbox = $this->createMock(ImapMailbox::class);
        $mailbox->expects(self::once())->method('connect');
        $mailbox->method('getUidValidity')->willReturn(123456);
        $mailbox->method('searchMessages')->willReturn([]);
        $mailbox->expects(self::never())->method('readMessage');
        $mailbox->expects(self::never())->method('markMessageAsSeen');
        $mailbox->expects(self::never())->method('moveMessage');
        $mailbox->expects(self::once())->method('disconnect');
        $historyRepository = $this->createStub(
            MailProcessingHistoryRepository::class,
        );
        $requestHistory = [];
        $handlerStack = HandlerStack::create(new MockHandler());
        $handlerStack->push(Middleware::history($requestHistory));
        $service = new MailProcessingService(
            $mailRuleRepository,
            $mailbox,
            new MimeMessageDecoder(),
            $historyRepository,
            new SlackClient(
                new Client(['handler' => $handlerStack]),
                'xoxb-example-bot-token',
            ),
            'example-mailbox',
        );

        self::assertSame(
            ['success' => true, 'failure_count' => 0],
            $service->process(),
        );
        self::assertCount(0, $requestHistory);
    }

    public function testSlackFailureDoesNotMoveMessage(): void
    {
        $mailRuleRepository = $this->createStub(MailRuleRepository::class);
        $mailRuleRepository->method('findEnabledRules')->willReturn([
            [
                'target_from' => 'example-sender',
                'to_folder' => 'ExampleArchive',
                'channel_id' => 'C0000000000',
            ],
        ]);
        $mailbox = $this->createMock(ImapMailbox::class);
        $mailbox->expects(self::once())->method('connect');
        $mailbox->method('getUidValidity')->willReturn(123456);
        $mailbox->method('searchMessages')->willReturn([
            [
                'uid' => 101,
                'sender' => 'example-sender@example.test',
                'subject' => 'Example received message.',
            ],
        ]);
        $mailbox->method('readMessage')->willReturn([
            'subject' => 'Example subject.',
            'body' => 'Example body.',
        ]);
        $mailbox->expects(self::never())->method('markMessageAsSeen');
        $mailbox->expects(self::never())->method('moveMessage');
        $mailbox->expects(self::once())->method('disconnect');
        $historyRepository = $this->createMock(
            MailProcessingHistoryRepository::class,
        );
        $historyRepository->method('find')->willReturn(null);
        $historyRepository
            ->expects(self::never())
            ->method('recordSlackPosted');
        $historyRepository->expects(self::never())->method('markCompleted');
        $service = new MailProcessingService(
            $mailRuleRepository,
            $mailbox,
            new MimeMessageDecoder(),
            $historyRepository,
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
            'example-mailbox',
        );

        self::assertSame(
            ['success' => false, 'failure_count' => 1],
            $service->process(),
        );
    }

    public function testBodyFetchFailureDoesNotProcessMessageAndContinues(): void
    {
        $mailRuleRepository = $this->createStub(MailRuleRepository::class);
        $mailRuleRepository->method('findEnabledRules')->willReturn([
            [
                'target_from' => 'example-sender',
                'to_folder' => 'ExampleArchive',
                'channel_id' => 'C0000000000',
            ],
        ]);
        $mailbox = $this->createMock(ImapMailbox::class);
        $mailbox->expects(self::once())->method('connect');
        $mailbox->method('getUidValidity')->willReturn(123456);
        $mailbox->method('searchMessages')->willReturn([
            [
                'uid' => 101,
                'sender' => 'example-sender@example.test',
                'subject' => 'Example first message.',
            ],
            [
                'uid' => 102,
                'sender' => 'example-sender@example.test',
                'subject' => 'Example second message.',
            ],
        ]);
        $mailbox
            ->expects(self::exactly(2))
            ->method('readMessage')
            ->willReturnCallback(
                static function (
                    int $uid,
                    MimeMessageDecoder $decoder,
                ): array {
                    self::assertInstanceOf(
                        MimeMessageDecoder::class,
                        $decoder,
                    );

                    if ($uid === 101) {
                        throw new RuntimeException(
                            'IMAP message body fetch failed.',
                        );
                    }

                    self::assertSame(102, $uid);

                    return [
                        'subject' => 'Example successful subject.',
                        'body' => 'Example successful body.',
                    ];
                },
            );
        $mailbox
            ->expects(self::once())
            ->method('markMessageAsSeen')
            ->with(102);
        $mailbox
            ->expects(self::once())
            ->method('moveMessage')
            ->with(102, 'ExampleArchive');
        $mailbox->expects(self::once())->method('disconnect');
        $historyRepository = $this->createMock(
            MailProcessingHistoryRepository::class,
        );
        $historyRepository
            ->expects(self::exactly(2))
            ->method('find')
            ->willReturn(null);
        $historyRepository
            ->expects(self::once())
            ->method('recordSlackPosted')
            ->with(
                'example-mailbox',
                123456,
                102,
                '1234567890.123456',
            );
        $historyRepository
            ->expects(self::once())
            ->method('markCompleted')
            ->with('example-mailbox', 123456, 102);
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
        $service = new MailProcessingService(
            $mailRuleRepository,
            $mailbox,
            new MimeMessageDecoder(),
            $historyRepository,
            new SlackClient(
                new Client(['handler' => $handlerStack]),
                'xoxb-example-bot-token',
            ),
            'example-mailbox',
        );

        self::assertSame(
            ['success' => false, 'failure_count' => 1],
            $service->process(),
        );
        self::assertCount(1, $requestHistory);
    }

    public function testSeenFailureKeepsSlackPostedHistoryIncomplete(): void
    {
        $mailRuleRepository = $this->createStub(MailRuleRepository::class);
        $mailRuleRepository->method('findEnabledRules')->willReturn([
            [
                'target_from' => 'example-sender',
                'to_folder' => 'ExampleArchive',
                'channel_id' => 'C0000000000',
            ],
        ]);
        $mailbox = $this->createMock(ImapMailbox::class);
        $mailbox->expects(self::once())->method('connect');
        $mailbox->method('getUidValidity')->willReturn(123456);
        $mailbox->method('searchMessages')->willReturn([
            [
                'uid' => 101,
                'sender' => 'example-sender@example.test',
                'subject' => 'Example received message.',
            ],
        ]);
        $mailbox->method('readMessage')->willReturn([
            'subject' => 'Example subject.',
            'body' => 'Example body.',
        ]);
        $mailbox
            ->expects(self::once())
            ->method('markMessageAsSeen')
            ->with(101)
            ->willThrowException(
                new RuntimeException('IMAP message update failed.'),
            );
        $mailbox->expects(self::never())->method('moveMessage');
        $mailbox->expects(self::once())->method('disconnect');
        $historyRepository = $this->createMock(
            MailProcessingHistoryRepository::class,
        );
        $historyRepository->method('find')->willReturn(null);
        $historyRepository
            ->expects(self::once())
            ->method('recordSlackPosted')
            ->with(
                'example-mailbox',
                123456,
                101,
                '1234567890.123456',
            );
        $historyRepository->expects(self::never())->method('markCompleted');
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
        $service = new MailProcessingService(
            $mailRuleRepository,
            $mailbox,
            new MimeMessageDecoder(),
            $historyRepository,
            new SlackClient(
                new Client(['handler' => $handlerStack]),
                'xoxb-example-bot-token',
            ),
            'example-mailbox',
        );

        self::assertSame(
            ['success' => false, 'failure_count' => 1],
            $service->process(),
        );
        self::assertCount(1, $requestHistory);
    }

    public function testExpungeFailureKeepsSlackPostedHistoryIncomplete(): void
    {
        $mailRuleRepository = $this->createStub(MailRuleRepository::class);
        $mailRuleRepository->method('findEnabledRules')->willReturn([
            [
                'target_from' => 'example-sender',
                'to_folder' => 'ExampleArchive',
                'channel_id' => 'C0000000000',
            ],
        ]);
        $mailbox = $this->createMock(ImapMailbox::class);
        $mailbox->expects(self::once())->method('connect');
        $mailbox->method('getUidValidity')->willReturn(123456);
        $mailbox->method('searchMessages')->willReturn([
            [
                'uid' => 101,
                'sender' => 'example-sender@example.test',
                'subject' => 'Example received message.',
            ],
        ]);
        $mailbox->method('readMessage')->willReturn([
            'subject' => 'Example subject.',
            'body' => 'Example body.',
        ]);
        $mailbox->expects(self::once())->method('markMessageAsSeen');
        $mailbox
            ->expects(self::once())
            ->method('moveMessage')
            ->willThrowException(
                new RuntimeException('IMAP message move failed.'),
            );
        $mailbox->expects(self::once())->method('disconnect');
        $historyRepository = $this->createMock(
            MailProcessingHistoryRepository::class,
        );
        $historyRepository->method('find')->willReturn(null);
        $historyRepository
            ->expects(self::once())
            ->method('recordSlackPosted');
        $historyRepository->expects(self::never())->method('markCompleted');
        $service = new MailProcessingService(
            $mailRuleRepository,
            $mailbox,
            new MimeMessageDecoder(),
            $historyRepository,
            new SlackClient(
                new Client([
                    'handler' => new MockHandler([
                        new Response(
                            200,
                            [],
                            '{"ok":true,"ts":"1234567890.123456"}',
                        ),
                    ]),
                ]),
                'xoxb-example-bot-token',
            ),
            'example-mailbox',
        );

        self::assertSame(
            ['success' => false, 'failure_count' => 1],
            $service->process(),
        );
    }

    public function testRetryAfterMoveFailureDoesNotPostAgain(): void
    {
        $mailRuleRepository = $this->createStub(MailRuleRepository::class);
        $mailRuleRepository->method('findEnabledRules')->willReturn([
            [
                'target_from' => 'example-sender',
                'to_folder' => 'ExampleArchive',
                'channel_id' => 'C0000000000',
            ],
        ]);
        $mailbox = $this->createMock(ImapMailbox::class);
        $mailbox->expects(self::once())->method('connect');
        $mailbox->method('getUidValidity')->willReturn(123456);
        $mailbox->method('searchMessages')->willReturn([
            [
                'uid' => 101,
                'sender' => 'example-sender@example.test',
                'subject' => 'Example received message.',
            ],
        ]);
        $mailbox->expects(self::never())->method('readMessage');
        $mailbox
            ->expects(self::once())
            ->method('markMessageAsSeen')
            ->with(101);
        $mailbox
            ->expects(self::once())
            ->method('moveMessage')
            ->with(101, 'ExampleArchive');
        $mailbox->expects(self::once())->method('disconnect');
        $historyRepository = $this->createMock(
            MailProcessingHistoryRepository::class,
        );
        $historyRepository->method('find')->willReturn([
            'slack_posted' => true,
            'completed' => false,
            'slack_timestamp' => '1234567890.123456',
        ]);
        $historyRepository
            ->expects(self::never())
            ->method('recordSlackPosted');
        $historyRepository
            ->expects(self::once())
            ->method('markCompleted')
            ->with('example-mailbox', 123456, 101);
        $requestHistory = [];
        $handlerStack = HandlerStack::create(new MockHandler());
        $handlerStack->push(Middleware::history($requestHistory));
        $service = new MailProcessingService(
            $mailRuleRepository,
            $mailbox,
            new MimeMessageDecoder(),
            $historyRepository,
            new SlackClient(
                new Client(['handler' => $handlerStack]),
                'xoxb-example-bot-token',
            ),
            'example-mailbox',
        );

        self::assertSame(
            ['success' => true, 'failure_count' => 0],
            $service->process(),
        );
        self::assertCount(0, $requestHistory);
    }
}
