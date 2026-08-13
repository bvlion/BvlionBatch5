<?php

declare(strict_types=1);

namespace BvlionBatch5\Mail;

use RuntimeException;
use stdClass;
use ValueError;

final class MimeMessageDecoder
{
    private const MAX_BODY_LENGTH = 4000;

    public function decodeSubject(string $encodedSubject): string
    {
        $elements = imap_mime_header_decode($encodedSubject);

        if ($elements === false) {
            return '';
        }

        $subject = '';

        foreach ($elements as $element) {
            $text = (string) ($element->text ?? '');
            $charset = strtoupper((string) ($element->charset ?? ''));

            if (
                $charset === ''
                || $charset === 'DEFAULT'
                || $charset === 'US-ASCII'
                || $charset === 'UTF-8'
            ) {
                $subject .= mb_scrub($text, 'UTF-8');
                continue;
            }

            try {
                $subject .= mb_convert_encoding(
                    $text,
                    'UTF-8',
                    $charset,
                );
            } catch (ValueError) {
                continue;
            }
        }

        return $subject;
    }

    /**
     * @param callable(string): (string|false) $bodyFetcher
     *        IMAPのsection番号（例: 1、1.2）を受け取ります。
     */
    public function decodeBody(
        stdClass $structure,
        callable $bodyFetcher,
    ): string {
        $body = $this->findBodyBySubtype(
            $structure,
            (int) ($structure->type ?? -1) === TYPEMULTIPART ? '' : '1',
            $bodyFetcher,
            'PLAIN',
        );

        if ($body === null) {
            return '';
        }

        return mb_substr($body, 0, self::MAX_BODY_LENGTH, 'UTF-8');
    }

    /**
     * @param callable(string): (string|false) $bodyFetcher
     *        IMAPのsection番号（例: 1、1.2）を受け取ります。
     */
    public function decodeHtmlBody(
        stdClass $structure,
        callable $bodyFetcher,
    ): string {
        return $this->findBodyBySubtype(
            $structure,
            (int) ($structure->type ?? -1) === TYPEMULTIPART ? '' : '1',
            $bodyFetcher,
            'HTML',
        ) ?? '';
    }

    /**
     * @param callable(string): (string|false) $bodyFetcher
     */
    private function findBodyBySubtype(
        stdClass $part,
        string $partNumber,
        callable $bodyFetcher,
        string $subtype,
    ): ?string {
        $isAttachment = strtoupper(
            (string) ($part->disposition ?? ''),
        ) === 'ATTACHMENT';

        foreach (
            [
                $part->parameters ?? [],
                $part->dparameters ?? [],
            ] as $parameters
        ) {
            if (!is_array($parameters)) {
                continue;
            }

            foreach ($parameters as $parameter) {
                $attribute = strtolower(
                    (string) ($parameter->attribute ?? ''),
                );

                if ($attribute === 'filename' || $attribute === 'name') {
                    $isAttachment = true;
                    break 2;
                }
            }
        }

        if ($isAttachment) {
            return null;
        }

        if ((int) ($part->type ?? -1) === TYPEMULTIPART) {
            $parts = $part->parts ?? [];

            if (!is_array($parts)) {
                return null;
            }

            foreach ($parts as $index => $childPart) {
                if (!$childPart instanceof stdClass) {
                    continue;
                }

                $childPartNumber = $partNumber === ''
                    ? (string) ($index + 1)
                    : $partNumber . '.' . ($index + 1);
                $body = $this->findBodyBySubtype(
                    $childPart,
                    $childPartNumber,
                    $bodyFetcher,
                    $subtype,
                );

                if ($body !== null) {
                    return $body;
                }
            }

            return null;
        }

        if (
            (int) ($part->type ?? -1) !== TYPETEXT
            || strtoupper((string) ($part->subtype ?? '')) !== $subtype
        ) {
            return null;
        }

        $body = $bodyFetcher($partNumber);

        if ($body === false) {
            throw new RuntimeException('IMAP message body fetch failed.');
        }

        $encoding = (int) ($part->encoding ?? ENC7BIT);

        if ($encoding === ENCBASE64) {
            $decodedBody = base64_decode($body, true);

            if ($decodedBody === false) {
                return null;
            }

            $body = $decodedBody;
        } elseif ($encoding === ENCQUOTEDPRINTABLE) {
            $body = quoted_printable_decode($body);
        }

        $charset = 'UTF-8';
        $parameters = $part->parameters ?? [];

        if (is_array($parameters)) {
            foreach ($parameters as $parameter) {
                if (
                    strtolower(
                        (string) ($parameter->attribute ?? ''),
                    ) === 'charset'
                ) {
                    $charset = (string) ($parameter->value ?? 'UTF-8');
                    break;
                }
            }
        }

        $normalizedCharset = strtoupper($charset);

        if (
            $normalizedCharset === ''
            || $normalizedCharset === 'DEFAULT'
            || $normalizedCharset === 'US-ASCII'
            || $normalizedCharset === 'UTF-8'
        ) {
            return mb_scrub($body, 'UTF-8');
        }

        try {
            return mb_convert_encoding($body, 'UTF-8', $charset);
        } catch (ValueError) {
            return null;
        }
    }
}
