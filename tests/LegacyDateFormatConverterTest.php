<?php

declare(strict_types=1);

namespace BvlionBatch5\Tests;

use BvlionBatch5\Mail\LegacyDateFormatConverter;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class LegacyDateFormatConverterTest extends TestCase
{
    private function exampleDate(): DateTimeImmutable
    {
        return new DateTimeImmutable(
            '2026-03-05 07:09:02',
            new DateTimeZone('Asia/Tokyo'),
        );
    }

    public function testConvertsYearMonthDayHourMinute(): void
    {
        $converter = new LegacyDateFormatConverter();

        $phpFormat = $converter->toPhpFormat('yyyy/MM/dd HH:mm');

        self::assertSame(
            '2026/03/05 07:09',
            $this->exampleDate()->format($phpFormat),
        );
    }

    public function testPreservesFixedLiteralText(): void
    {
        $converter = new LegacyDateFormatConverter();

        $phpFormat = $converter->toPhpFormat("'受信 'yyyy-MM-dd");

        self::assertSame(
            '受信 2026-03-05',
            $this->exampleDate()->format($phpFormat),
        );
    }

    public function testUnquotedNonLetterCharactersArePreservedLiterally(): void
    {
        $converter = new LegacyDateFormatConverter();

        $phpFormat = $converter->toPhpFormat('[yyyy-MM-dd HH:mm] ');

        self::assertSame(
            '[2026-03-05 07:09] ',
            $this->exampleDate()->format($phpFormat),
        );
    }

    public function testDoubledSingleQuoteProducesALiteralQuote(): void
    {
        $converter = new LegacyDateFormatConverter();

        $phpFormat = $converter->toPhpFormat("HH''mm");

        self::assertSame(
            "07'09",
            $this->exampleDate()->format($phpFormat),
        );
    }

    public function testFormatsInAsiaTokyoRegardlessOfSourceTimezone(): void
    {
        $converter = new LegacyDateFormatConverter();
        $phpFormat = $converter->toPhpFormat('HH:mm');
        $utcDate = new DateTimeImmutable(
            '2026-03-04 22:09:02',
            new DateTimeZone('UTC'),
        );

        self::assertSame(
            '07:09',
            $utcDate
                ->setTimezone(new DateTimeZone('Asia/Tokyo'))
                ->format($phpFormat),
        );
    }

    public function testSingleDigitMonthDayHourTokensAreNotZeroPadded(): void
    {
        $converter = new LegacyDateFormatConverter();
        $date = new DateTimeImmutable(
            '2026-03-05 07:09:02',
            new DateTimeZone('Asia/Tokyo'),
        );

        self::assertSame(
            '2026/3/5 7',
            $date->format($converter->toPhpFormat('y/M/d H')),
        );
    }

    public function testMinuteTokenIsAlwaysZeroPadded(): void
    {
        $converter = new LegacyDateFormatConverter();
        $date = new DateTimeImmutable(
            '2026-03-05 07:09:02',
            new DateTimeZone('Asia/Tokyo'),
        );

        self::assertSame(
            '09',
            $date->format($converter->toPhpFormat('m')),
        );
    }

    public function testUnsupportedMonthNameTokenThrows(): void
    {
        $converter = new LegacyDateFormatConverter();

        $this->expectException(RuntimeException::class);

        $converter->toPhpFormat('MMM dd');
    }

    public function testUnsupportedLetterTokenThrows(): void
    {
        $converter = new LegacyDateFormatConverter();

        $this->expectException(RuntimeException::class);

        $converter->toPhpFormat('EEE yyyy-MM-dd');
    }

    public function testUnterminatedQuotedLiteralThrows(): void
    {
        $converter = new LegacyDateFormatConverter();

        $this->expectException(RuntimeException::class);

        $converter->toPhpFormat("yyyy'MM-dd");
    }
}
