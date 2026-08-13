<?php

declare(strict_types=1);

namespace BvlionBatch5\Tests;

use BvlionBatch5\Mail\HtmlToPdfConverter;
use PHPUnit\Framework\TestCase;

final class HtmlToPdfConverterTest extends TestCase
{
    public function testConvertsHtmlToPdfBinary(): void
    {
        $pdf = (new HtmlToPdfConverter())->convert(
            '<html><body><p>Example HTML body.</p></body></html>',
        );

        self::assertStringStartsWith('%PDF-', $pdf);
    }

    public function testConvertsHtmlWithJapaneseText(): void
    {
        $pdf = (new HtmlToPdfConverter())->convert(
            '<html><body><p>架空のメール本文です。</p></body></html>',
        );

        self::assertStringStartsWith('%PDF-', $pdf);
    }

    public function testRemoteImageReferenceDoesNotFailConversion(): void
    {
        $pdf = (new HtmlToPdfConverter())->convert(
            '<html><body>'
            . '<img src="https://example.test/example.png">'
            . '<p>Example body with a remote image reference.</p>'
            . '</body></html>',
        );

        self::assertStringStartsWith('%PDF-', $pdf);
    }
}
