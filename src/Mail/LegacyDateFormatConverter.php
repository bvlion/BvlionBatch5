<?php

declare(strict_types=1);

namespace BvlionBatch5\Mail;

use DateTimeImmutable;
use RuntimeException;

/**
 * Formats a DateTimeImmutable using a legacy BvlionBatch4 prefix_format
 * pattern (an Apache Commons FastDateFormat / java.text.SimpleDateFormat
 * pattern). PHP's own DateTimeInterface::format() uses different letters
 * for the same meaning, and has no directive for a non-zero-padded
 * minute or second, so the legacy pattern cannot simply be translated
 * into a PHP format string and passed to format() as-is; each token is
 * instead resolved and rendered directly against the given date.
 *
 * Only the token set needed to reproduce the old mail Slack display
 * name (year, month, day, hour, minute, second and literal text) is
 * supported. An unsupported token throws rather than silently
 * producing a wrong date, since a mistranslated format would post an
 * incorrect timestamp to Slack.
 */
final class LegacyDateFormatConverter
{
    public function format(DateTimeImmutable $date, string $legacyFormat): string
    {
        $characters = mb_str_split($legacyFormat, 1, 'UTF-8');
        $length = count($characters);
        $result = '';
        $index = 0;

        while ($index < $length) {
            $character = $characters[$index];

            if ($character === "'") {
                [$literal, $index] = $this->readQuotedLiteral(
                    $characters,
                    $index,
                );
                $result .= $literal;

                continue;
            }

            if ($this->isAsciiLetter($character)) {
                $runStart = $index;

                while (
                    $index < $length
                    && $characters[$index] === $character
                ) {
                    $index++;
                }

                $result .= $this->formatToken(
                    $date,
                    $character,
                    $index - $runStart,
                );

                continue;
            }

            $result .= $character;
            $index++;
        }

        return $result;
    }

    /**
     * Renders a single FastDateFormat token run. FastDateFormat's
     * Number rule treats the pattern letter count as the minimum digit
     * count: a single letter (e.g. "m") is not zero-padded, while two
     * or more (e.g. "mm") is zero-padded to that width.
     */
    private function formatToken(
        DateTimeImmutable $date,
        string $character,
        int $count,
    ): string {
        return match ($character) {
            'y' => $count === 2 ? $date->format('y') : $date->format('Y'),
            'M' => match (true) {
                $count >= 3 => throw new RuntimeException(
                    'prefix_format uses an unsupported month token.',
                ),
                $count === 1 => $date->format('n'),
                default => $date->format('m'),
            },
            'd' => $count === 1 ? $date->format('j') : $date->format('d'),
            'H' => $count === 1 ? $date->format('G') : $date->format('H'),
            'h' => $count === 1 ? $date->format('g') : $date->format('h'),
            'm' => $count === 1
                ? $this->stripLeadingZero($date->format('i'))
                : $date->format('i'),
            's' => $count === 1
                ? $this->stripLeadingZero($date->format('s'))
                : $date->format('s'),
            default => throw new RuntimeException(sprintf(
                'prefix_format uses an unsupported token: %s.',
                $character,
            )),
        };
    }

    private function stripLeadingZero(string $paddedDigits): string
    {
        $stripped = ltrim($paddedDigits, '0');

        return $stripped === '' ? '0' : $stripped;
    }

    /**
     * @param list<string> $characters
     * @return array{0: string, 1: int}
     */
    private function readQuotedLiteral(array $characters, int $index): array
    {
        $length = count($characters);
        $index++;

        if ($index < $length && $characters[$index] === "'") {
            return ["'", $index + 1];
        }

        $literal = '';

        while ($index < $length && $characters[$index] !== "'") {
            $literal .= $characters[$index];
            $index++;
        }

        if ($index >= $length) {
            throw new RuntimeException(
                'prefix_format contains an unterminated quoted literal.',
            );
        }

        return [$literal, $index + 1];
    }

    private function isAsciiLetter(string $character): bool
    {
        return preg_match('/^[A-Za-z]$/', $character) === 1;
    }
}
