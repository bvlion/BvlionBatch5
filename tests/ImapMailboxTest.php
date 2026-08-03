<?php

declare(strict_types=1);

namespace BvlionBatch5\Mail {
    function imap_open(
        string $mailbox,
        string $username,
        string $password,
        int $flags = 0,
        int $retries = 0,
        array $options = [],
    ): false {
        $GLOBALS['bvlion_batch5_imap_errors']
            = $GLOBALS['bvlion_batch5_next_imap_errors'] ?? false;
        $GLOBALS['bvlion_batch5_imap_alerts']
            = $GLOBALS['bvlion_batch5_next_imap_alerts'] ?? false;

        return false;
    }

    function imap_errors(): array|false
    {
        $errors = $GLOBALS['bvlion_batch5_imap_errors'] ?? false;
        $GLOBALS['bvlion_batch5_imap_errors'] = false;

        return $errors;
    }

    function imap_alerts(): array|false
    {
        $alerts = $GLOBALS['bvlion_batch5_imap_alerts'] ?? false;
        $GLOBALS['bvlion_batch5_imap_alerts'] = false;

        return $alerts;
    }
}

namespace BvlionBatch5\Tests {
    use BvlionBatch5\Mail\ImapMailbox;
    use PHPUnit\Framework\TestCase;
    use RuntimeException;

    final class ImapMailboxTest extends TestCase
    {
        public function testManualDiagnosticsRemoveConfigurationValues(): void
        {
            $GLOBALS['bvlion_batch5_next_imap_errors'] = [
                'No such host as imap.example.test.',
                'TLS certificate failure for imap.example.test with '
                . 'password=example-password.',
                'Unexpected server text with token=example-token.',
            ];
            $GLOBALS['bvlion_batch5_next_imap_alerts'] = [
                'Authentication failed for user@example.test.',
            ];
            $mailbox = new ImapMailbox(
                'imap.example.test',
                993,
                'user@example.test',
                'example-password',
            );

            try {
                $mailbox->connect(true);
                self::fail('RuntimeException was not thrown.');
            } catch (RuntimeException $exception) {
                self::assertStringStartsWith(
                    "IMAP connection failed.\nIMAP diagnostics: ",
                    $exception->getMessage(),
                );
                self::assertStringContainsString(
                    'Host resolution failed.',
                    $exception->getMessage(),
                );
                self::assertStringContainsString(
                    'TLS negotiation or certificate validation failed.',
                    $exception->getMessage(),
                );
                self::assertStringContainsString(
                    'Authentication failed.',
                    $exception->getMessage(),
                );
                self::assertStringContainsString(
                    'IMAP server returned an error.',
                    $exception->getMessage(),
                );
                self::assertStringNotContainsString(
                    'imap.example.test',
                    $exception->getMessage(),
                );
                self::assertStringNotContainsString(
                    'user@example.test',
                    $exception->getMessage(),
                );
                self::assertStringNotContainsString(
                    'example-password',
                    $exception->getMessage(),
                );
                self::assertStringNotContainsString(
                    'example-token',
                    $exception->getMessage(),
                );
            }

            self::assertFalse(\BvlionBatch5\Mail\imap_errors());
            self::assertFalse(\BvlionBatch5\Mail\imap_alerts());
        }

        public function testEmptyDiagnosticsUseOnlyFixedError(): void
        {
            $GLOBALS['bvlion_batch5_next_imap_errors'] = false;
            $GLOBALS['bvlion_batch5_next_imap_alerts'] = false;
            $mailbox = new ImapMailbox(
                'imap.example.test',
                993,
                'user@example.test',
                'example-password',
            );

            try {
                $mailbox->connect(true);
                self::fail('RuntimeException was not thrown.');
            } catch (RuntimeException $exception) {
                self::assertSame(
                    'IMAP connection failed.',
                    $exception->getMessage(),
                );
            }
        }

        public function testNormalConnectionFailureDoesNotExposeDiagnostics(): void
        {
            $GLOBALS['bvlion_batch5_next_imap_errors'] = [
                'Unable to connect to imap.example.test for '
                . 'user@example.test with password=example-password.',
            ];
            $GLOBALS['bvlion_batch5_next_imap_alerts'] = false;
            $mailbox = new ImapMailbox(
                'imap.example.test',
                993,
                'user@example.test',
                'example-password',
            );

            try {
                $mailbox->connect();
                self::fail('RuntimeException was not thrown.');
            } catch (RuntimeException $exception) {
                self::assertSame(
                    'IMAP connection failed.',
                    $exception->getMessage(),
                );
                self::assertStringNotContainsString(
                    'imap.example.test',
                    $exception->getMessage(),
                );
                self::assertStringNotContainsString(
                    'user@example.test',
                    $exception->getMessage(),
                );
                self::assertStringNotContainsString(
                    'example-password',
                    $exception->getMessage(),
                );
            }
        }
    }
}
