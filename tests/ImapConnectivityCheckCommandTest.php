<?php

declare(strict_types=1);

namespace BvlionBatch5\Tests;

use BvlionBatch5\Mail\ImapConnectivityCheckCommand;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ImapConnectivityCheckCommandTest extends TestCase
{
    public function testMissingAndEmptyValuesFailBeforeConnection(): void
    {
        $configurationCases = [
            [
                null,
                '993',
                'user@example.test',
                'example-password',
                'IMAP_HOST is required.',
            ],
            [
                '',
                '993',
                'user@example.test',
                'example-password',
                'IMAP_HOST is required.',
            ],
            [
                'imap.example.test',
                null,
                'user@example.test',
                'example-password',
                'IMAP_PORT is required.',
            ],
            [
                'imap.example.test',
                '',
                'user@example.test',
                'example-password',
                'IMAP_PORT is required.',
            ],
            [
                'imap.example.test',
                '993',
                null,
                'example-password',
                'IMAP_USERNAME is required.',
            ],
            [
                'imap.example.test',
                '993',
                '',
                'example-password',
                'IMAP_USERNAME is required.',
            ],
            [
                'imap.example.test',
                '993',
                'user@example.test',
                null,
                'IMAP_PASSWORD is required.',
            ],
            [
                'imap.example.test',
                '993',
                'user@example.test',
                '',
                'IMAP_PASSWORD is required.',
            ],
        ];

        foreach (
            $configurationCases as [
                $host,
                $port,
                $username,
                $password,
                $expectedMessage,
            ]
        ) {
            try {
                (new ImapConnectivityCheckCommand())->run(
                    $host,
                    $port,
                    $username,
                    $password,
                );
                self::fail('RuntimeException was not thrown.');
            } catch (RuntimeException $exception) {
                self::assertSame(
                    $expectedMessage,
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

    public function testInvalidPortsFailBeforeConnection(): void
    {
        $invalidPorts = [
            'example-port',
            '1.5',
            '0',
            '65536',
        ];

        foreach ($invalidPorts as $invalidPort) {
            try {
                (new ImapConnectivityCheckCommand())->run(
                    'imap.example.test',
                    $invalidPort,
                    'user@example.test',
                    'example-password',
                );
                self::fail('RuntimeException was not thrown.');
            } catch (RuntimeException $exception) {
                self::assertSame(
                    'IMAP_PORT must be an integer between 1 and 65535.',
                    $exception->getMessage(),
                );
                self::assertStringNotContainsString(
                    $invalidPort,
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
