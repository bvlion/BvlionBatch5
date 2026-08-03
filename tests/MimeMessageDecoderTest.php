<?php

declare(strict_types=1);

namespace BvlionBatch5\Tests;

use BvlionBatch5\Mail\MimeMessageDecoder;
use PHPUnit\Framework\TestCase;

final class MimeMessageDecoderTest extends TestCase
{
    public function testDecodesMimeEncodedSubject(): void
    {
        $encodedSubject = '=?UTF-8?B?'
            . base64_encode('架空の件名')
            . '?=';

        self::assertSame(
            '架空の件名',
            (new MimeMessageDecoder())->decodeSubject($encodedSubject),
        );
    }

    public function testDecodesQuotedPrintablePlainText(): void
    {
        $sourceBody = mb_convert_encoding(
            'Example café body.',
            'ISO-8859-1',
            'UTF-8',
        );
        $structure = (object) [
            'type' => TYPETEXT,
            'subtype' => 'PLAIN',
            'encoding' => ENCQUOTEDPRINTABLE,
            'parameters' => [
                (object) [
                    'attribute' => 'charset',
                    'value' => 'ISO-8859-1',
                ],
            ],
        ];

        $body = (new MimeMessageDecoder())->decodeBody(
            $structure,
            static function (string $partNumber) use ($sourceBody): string {
                self::assertSame('1', $partNumber);

                return quoted_printable_encode($sourceBody);
            },
        );

        self::assertSame('Example café body.', $body);
    }

    public function testRecursivelyPrefersBase64PlainText(): void
    {
        $structure = (object) [
            'type' => TYPEMULTIPART,
            'subtype' => 'MIXED',
            'parts' => [
                (object) [
                    'type' => TYPEMULTIPART,
                    'subtype' => 'ALTERNATIVE',
                    'parts' => [
                        (object) [
                            'type' => TYPETEXT,
                            'subtype' => 'HTML',
                            'encoding' => ENCQUOTEDPRINTABLE,
                        ],
                        (object) [
                            'type' => TYPETEXT,
                            'subtype' => 'PLAIN',
                            'encoding' => ENCBASE64,
                            'parameters' => [
                                (object) [
                                    'attribute' => 'charset',
                                    'value' => 'UTF-8',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
        $partBodies = [
            '1.1' => '<p>Example HTML body.</p>',
            '1.2' => base64_encode('Example plain body.'),
        ];
        $fetchedPartNumbers = [];

        $body = (new MimeMessageDecoder())->decodeBody(
            $structure,
            static function (string $partNumber) use (
                &$fetchedPartNumbers,
                $partBodies,
            ): string {
                self::assertArrayHasKey($partNumber, $partBodies);
                $fetchedPartNumbers[] = $partNumber;

                return $partBodies[$partNumber];
            },
        );

        self::assertSame('Example plain body.', $body);
        self::assertSame(['1.2'], $fetchedPartNumbers);
    }

    public function testHtmlOnlyReturnsEmptyBody(): void
    {
        $structure = (object) [
            'type' => TYPETEXT,
            'subtype' => 'HTML',
            'encoding' => ENCQUOTEDPRINTABLE,
        ];
        $bodyFetcher = static function (string $partNumber): string {
            self::fail('HTML body must not be fetched.');
        };

        self::assertSame(
            '',
            (new MimeMessageDecoder())->decodeBody(
                $structure,
                $bodyFetcher,
            ),
        );
    }

    public function testDeeplyNestedMultipartUsesImapSectionNumber(): void
    {
        $structure = (object) [
            'type' => TYPEMULTIPART,
            'subtype' => 'MIXED',
            'parts' => [
                (object) [
                    'type' => TYPETEXT,
                    'subtype' => 'HTML',
                    'encoding' => ENC7BIT,
                ],
                (object) [
                    'type' => TYPEMULTIPART,
                    'subtype' => 'MIXED',
                    'parts' => [
                        (object) [
                            'type' => TYPEMULTIPART,
                            'subtype' => 'ALTERNATIVE',
                            'parts' => [
                                (object) [
                                    'type' => TYPETEXT,
                                    'subtype' => 'PLAIN',
                                    'encoding' => ENC7BIT,
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $body = (new MimeMessageDecoder())->decodeBody(
            $structure,
            static function (string $partNumber): string {
                self::assertSame('2.1.1', $partNumber);

                return 'Example deeply nested body.';
            },
        );

        self::assertSame('Example deeply nested body.', $body);
    }

    public function testTextAttachmentIsNotUsedAsBody(): void
    {
        $structure = (object) [
            'type' => TYPEMULTIPART,
            'subtype' => 'MIXED',
            'parts' => [
                (object) [
                    'type' => TYPETEXT,
                    'subtype' => 'PLAIN',
                    'encoding' => ENCBASE64,
                    'disposition' => 'ATTACHMENT',
                    'dparameters' => [
                        (object) [
                            'attribute' => 'filename',
                            'value' => 'example.txt',
                        ],
                    ],
                ],
                (object) [
                    'type' => TYPETEXT,
                    'subtype' => 'PLAIN',
                    'encoding' => ENC7BIT,
                ],
            ],
        ];
        $partBodies = [
            '1' => base64_encode('Example attachment content.'),
            '2' => 'Example message body.',
        ];

        $body = (new MimeMessageDecoder())->decodeBody(
            $structure,
            static fn (string $partNumber): string|false =>
                $partBodies[$partNumber] ?? false,
        );

        self::assertSame('Example message body.', $body);
    }

    public function testBodyIsLimitedToFourThousandCharacters(): void
    {
        $structure = (object) [
            'type' => TYPETEXT,
            'subtype' => 'PLAIN',
            'encoding' => ENC7BIT,
        ];

        $body = (new MimeMessageDecoder())->decodeBody(
            $structure,
            static fn (string $partNumber): string => str_repeat(
                'あ',
                5000,
            ),
        );

        self::assertSame(4000, mb_strlen($body, 'UTF-8'));
        self::assertSame(str_repeat('あ', 4000), $body);
    }
}
