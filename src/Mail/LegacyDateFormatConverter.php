<?php

declare(strict_types=1);

namespace BvlionBatch5\Mail;

use RuntimeException;

/**
 * Converts a legacy BvlionBatch4 prefix_format pattern (an Apache
 * Commons FastDateFormat / java.text.SimpleDateFormat pattern) into
 * an equivalent PHP DateTimeInterface::format() pattern. PHP's own
 * format() uses different letters for the same meaning, so the
 * legacy pattern cannot be passed to it directly.
 *
 * Only the token set needed to reproduce the old mail Slack display
 * name (year, month, day, hour, minute, second and literal text) is
 * supported. An unsupported token throws rather than silently
 * producing a wrong date, since a mistranslated format would post an
 * incorrect timestamp to Slack.
 */
final class LegacyDateFormatConverter
{
    public function toPhpFormat(string $legacyFormat): string
    {
        $characters = mb_str_split($legacyFormat, 1, 'UTF-8');
        $length = count($characters);
        $phpFormat = '';
        $index = 0;

        while ($index < $length) {
            $character = $characters[$index];

            if ($character === "'") {
                [$literal, $index] = $this->readQuotedLiteral(
                    $characters,
                    $index,
                );
                $phpFormat .= $this->escapeLiteral($literal);

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

                $phpFormat .= $this->convertToken(
                    $character,
                    $index - $runStart,
                );

                continue;
            }

            $phpFormat .= $this->escapeLiteral($character);
            $index++;
        }

        return $phpFormat;
    }

    private function convertToken(string $character, int $count): string
    {
        return match ($character) {
            'y' => $count === 2 ? 'y' : 'Y',
            'M' => match (true) {
                $count >= 3 => throw new RuntimeException(
                    'prefix_format uses an unsupported month token.',
                ),
                $count === 1 => 'n',
                default => 'm',
            },
            'd' => $count === 1 ? 'j' : 'd',
            'H' => $count === 1 ? 'G' : 'H',
            'h' => $count === 1 ? 'g' : 'h',
            'm' => 'i',
            's' => 's',
            default => throw new RuntimeException(sprintf(
                'prefix_format uses an unsupported token: %s.',
                $character,
            )),
        };
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

    private function escapeLiteral(string $literal): string
    {
        $escaped = '';

        foreach (mb_str_split($literal, 1, 'UTF-8') as $character) {
            $escaped .= $this->isAsciiLetter($character) || $character === '\\'
                ? '\\' . $character
                : $character;
        }

        return $escaped;
    }

    private function isAsciiLetter(string $character): bool
    {
        return preg_match('/^[A-Za-z]$/', $character) === 1;
    }
}
