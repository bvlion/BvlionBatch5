<?php

declare(strict_types=1);

namespace BvlionBatch5\Tests;

use BvlionBatch5\Mail\HtmlToPdfConverter;
use FontLib\Font;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class HtmlToPdfConverterTest extends TestCase
{
    public function testConvertsHtmlToPdfBinary(): void
    {
        $pdf = (new HtmlToPdfConverter())->convert(
            '<html><body><p>Example HTML body.</p></body></html>',
        );

        self::assertStringStartsWith('%PDF-', $pdf);
    }

    /**
     * Verifies the Japanese text is actually rendered with a
     * genuine, glyph-bearing CJK font, not merely that a PDF was
     * produced. A regression here (e.g. the font failing to
     * register, or Dompdf falling back to a core font) would
     * otherwise still pass a "starts with %PDF-" check while
     * rendering the Japanese text as blank or garbled glyphs.
     */
    public function testConvertsHtmlWithJapaneseText(): void
    {
        $pdf = (new HtmlToPdfConverter())->convert(
            '<html><body><p>架空のメール本文です。</p></body></html>',
        );

        self::assertStringStartsWith('%PDF-', $pdf);
        self::assertStringContainsString('IPAexGothic', $pdf);
        // TrueType CID embedding (CIDFontType2/FontFile2), not the
        // CFF/OpenType flavor (CIDFontType0/FontFile3): dompdf's font
        // embedding only reliably supports the former, confirmed by
        // manually rasterizing PDFs from both during development (see
        // PR description for that manual check).
        self::assertStringContainsString('/Subtype /CIDFontType2', $pdf);
        self::assertStringNotContainsString('/Subtype /CIDFontType0', $pdf);

        $font = $this->loadEmbeddedFontFile2($pdf);

        self::assertSame('TrueType', $font->getFontType());
        self::assertGreaterThan(0, $font->getData('head', 'unitsPerEm'));
        // More than 1 confirms actual Japanese glyphs were subsetted
        // in beyond the mandatory .notdef glyph (index 0).
        self::assertGreaterThan(1, $font->getData('maxp', 'numGlyphs'));
    }

    /**
     * Dompdf does not fall back through a font stack per glyph the
     * way a browser does, so a mail's own font-family must be
     * overridden outright rather than merely supplemented with a
     * Japanese fallback.
     */
    public function testEmailsOwnFontFamilyDoesNotOverrideJapaneseFont(): void
    {
        $pdf = (new HtmlToPdfConverter())->convert(
            '<html><head><style>body{font-family:Arial,sans-serif;}'
            . '</style></head><body>'
            . '<p style="font-family:Helvetica, sans-serif">'
            . '架空のメール本文です。</p></body></html>',
        );

        self::assertJapaneseFontIsSelected($pdf);
    }

    /**
     * A mail's own `!important` (or a highly specific selector) on
     * font-family cannot be beaten by an injected CSS override rule
     * of ours, since author-origin `!important` declarations are
     * compared to each other by specificity/source order, not
     * automatically beaten by another `!important` rule. This is why
     * HtmlToPdfConverter does not try to win that cascade at all: it
     * instead makes every font name Dompdf can resolve (see
     * HtmlToPdfConverter::OVERRIDDEN_FONT_FAMILIES) resolve to the
     * Japanese font, so whichever family name wins the cascade is
     * irrelevant. These three cases mirror the ones raised in review.
     */
    public function testImportantInlineFontFamilyDoesNotOverrideJapaneseFont(): void
    {
        $pdf = (new HtmlToPdfConverter())->convert(
            '<html><body>'
            . '<p style="font-family:Helvetica !important">'
            . '架空のメール本文です。</p></body></html>',
        );

        self::assertJapaneseFontIsSelected($pdf);
    }

    public function testImportantIdSelectorFontFamilyDoesNotOverrideJapaneseFont(): void
    {
        $pdf = (new HtmlToPdfConverter())->convert(
            '<html><head><style>#message{font-family:Arial !important;}'
            . '</style></head><body><p id="message">'
            . '架空のメール本文です。</p></body></html>',
        );

        self::assertJapaneseFontIsSelected($pdf);
    }

    public function testImportantInlineFontShorthandDoesNotOverrideJapaneseFont(): void
    {
        $pdf = (new HtmlToPdfConverter())->convert(
            '<html><body>'
            . '<p style="font: bold 14px Helvetica !important">'
            . '架空のメール本文です。</p></body></html>',
        );

        self::assertJapaneseFontIsSelected($pdf);
    }

    private static function assertJapaneseFontIsSelected(string $pdf): void
    {
        self::assertStringContainsString('IPAexGothic', $pdf);
        self::assertStringNotContainsString('/BaseFont /Helvetica', $pdf);
        self::assertStringNotContainsString('/BaseFont /Arial', $pdf);
        self::assertStringNotContainsString('/Subtype /CIDFontType0', $pdf);
    }

    /**
     * Dompdf is known to use a large multiple of the input HTML's
     * size in memory while rendering; an oversized mail must fail
     * that single mail rather than being truncated and partially
     * rendered, or risking exhausting memory/time for the whole
     * batch. See HtmlToPdfConverter::MAX_HTML_BYTES for the exact
     * threshold and rationale.
     */
    public function testOversizedHtmlFailsWithoutExposingContent(): void
    {
        $secretMarker = 'EXAMPLE-SECRET-MAIL-CONTENT';
        $oversizedHtml = '<html><body><p>' . $secretMarker
            . str_repeat('a', 5_000_000) . '</p></body></html>';

        try {
            (new HtmlToPdfConverter())->convert($oversizedHtml);
            self::fail('RuntimeException was not thrown.');
        } catch (RuntimeException $exception) {
            self::assertSame(
                'HTML body exceeds the maximum size allowed for PDF '
                    . 'conversion.',
                $exception->getMessage(),
            );
            self::assertStringNotContainsString(
                $secretMarker,
                $exception->getMessage(),
            );
        }
    }

    /**
     * Inline data URIs are part of the same HTML string handed to
     * Dompdf, so they must count toward the size limit like any
     * other byte -- a mail cannot smuggle an oversized body past the
     * check just by moving the bulk of it into a data URI.
     */
    public function testDataUriCountsTowardHtmlSizeLimit(): void
    {
        $oversizedHtml = '<html><body><img src="data:image/png;base64,'
            . str_repeat('A', 5_000_000) . '"></body></html>';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'HTML body exceeds the maximum size allowed for PDF '
                . 'conversion.',
        );

        (new HtmlToPdfConverter())->convert($oversizedHtml);
    }

    public function testEmbedsContentIdImageInPdf(): void
    {
        $imageContent = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwC'
                . 'AAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
            true,
        );
        self::assertIsString($imageContent);

        $pdf = (new HtmlToPdfConverter())->convert(
            '<html><body><img src="cid:logo%40example.test">'
                . '<p>Example body.</p></body></html>',
            [
                'logo@example.test' => [
                    'content_type' => 'image/png',
                    'content' => $imageContent,
                ],
            ],
        );

        self::assertStringStartsWith('%PDF-', $pdf);
        self::assertStringContainsString('/Subtype /Image', $pdf);
    }

    public function testFetchesAndEmbedsPublicHttpImageInPdf(): void
    {
        $imageContent = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwC'
                . 'AAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
            true,
        );
        self::assertIsString($imageContent);
        $requestHistory = [];
        $handlerStack = HandlerStack::create(new MockHandler([
            new Response(
                200,
                [
                    'Content-Type' => 'image/png',
                    'Content-Length' => (string) strlen($imageContent),
                ],
                $imageContent,
            ),
        ]));
        $handlerStack->push(Middleware::history($requestHistory));

        $pdf = (new HtmlToPdfConverter())->convert(
            '<html><body>'
                . '<img src="https://images.example.test/logo.png">'
                . '<p>Example body.</p></body></html>',
            [],
            new Client(['handler' => $handlerStack]),
            static function (string $host): array {
                self::assertSame('images.example.test', $host);

                return ['93.184.216.34'];
            },
        );

        self::assertCount(1, $requestHistory);
        self::assertFalse(
            $requestHistory[0]['options']['allow_redirects'],
        );
        self::assertSame(
            3,
            $requestHistory[0]['options']['connect_timeout'],
        );
        self::assertLessThanOrEqual(
            10,
            $requestHistory[0]['options']['timeout'],
        );
        self::assertArrayHasKey(
            CURLOPT_RESOLVE,
            $requestHistory[0]['options']['curl'],
        );
        self::assertStringStartsWith('%PDF-', $pdf);
        self::assertStringContainsString('/Subtype /Image', $pdf);
    }

    public function testRejectsPrivateAddressWithoutHttpRequest(): void
    {
        $requestHistory = [];
        $handlerStack = HandlerStack::create(new MockHandler([
            new Response(200, ['Content-Type' => 'image/png'], 'unused'),
        ]));
        $handlerStack->push(Middleware::history($requestHistory));

        $pdf = (new HtmlToPdfConverter())->convert(
            '<html><body><img src="http://127.0.0.1/private.png">'
                . '<p>Example body.</p></body></html>',
            [],
            new Client(['handler' => $handlerStack]),
        );

        self::assertCount(0, $requestHistory);
        self::assertStringStartsWith('%PDF-', $pdf);
        self::assertStringNotContainsString('/Subtype /Image', $pdf);
    }

    public function testRejectsHostnameResolvingToNonPublicAddress(): void
    {
        $requestHistory = [];
        $handlerStack = HandlerStack::create(new MockHandler([
            new Response(200, ['Content-Type' => 'image/png'], 'unused'),
        ]));
        $handlerStack->push(Middleware::history($requestHistory));

        $pdf = (new HtmlToPdfConverter())->convert(
            '<html><body>'
                . '<img src="https://images.example.test/private.png">'
                . '<p>Example body.</p></body></html>',
            [],
            new Client(['handler' => $handlerStack]),
            static fn (string $host): array => ['100.64.0.1'],
        );

        self::assertCount(0, $requestHistory);
        self::assertStringStartsWith('%PDF-', $pdf);
        self::assertStringNotContainsString('/Subtype /Image', $pdf);
    }

    public function testRejectsPrivateRedirectTarget(): void
    {
        $requestHistory = [];
        $handlerStack = HandlerStack::create(new MockHandler([
            new Response(
                302,
                ['Location' => 'http://169.254.169.254/example.png'],
            ),
            new Response(200, ['Content-Type' => 'image/png'], 'unused'),
        ]));
        $handlerStack->push(Middleware::history($requestHistory));

        $pdf = (new HtmlToPdfConverter())->convert(
            '<html><body>'
                . '<img src="https://images.example.test/redirect.png">'
                . '<p>Example body.</p></body></html>',
            [],
            new Client(['handler' => $handlerStack]),
            static fn (string $host): array => ['93.184.216.34'],
        );

        self::assertCount(1, $requestHistory);
        self::assertStringStartsWith('%PDF-', $pdf);
        self::assertStringNotContainsString('/Subtype /Image', $pdf);
    }

    public function testImageFetchFailureDoesNotFailConversion(): void
    {
        $pdf = (new HtmlToPdfConverter())->convert(
            '<html><body>'
                . '<img src="https://images.example.test/missing.png">'
                . '<p>Example body with a missing image.</p>'
                . '</body></html>',
            [],
            new Client([
                'handler' => new MockHandler([
                    new Response(503, ['Content-Type' => 'image/png']),
                ]),
            ]),
            static fn (string $host): array => ['93.184.216.34'],
        );

        self::assertStringStartsWith('%PDF-', $pdf);
        self::assertStringNotContainsString('/Subtype /Image', $pdf);
    }

    public function testRejectsOversizedImageFromContentLength(): void
    {
        $pdf = (new HtmlToPdfConverter())->convert(
            '<html><body>'
                . '<img src="https://images.example.test/large.png">'
                . '<p>Example body.</p></body></html>',
            [],
            new Client([
                'handler' => new MockHandler([
                    new Response(
                        200,
                        [
                            'Content-Type' => 'image/png',
                            'Content-Length' => (string) (
                                HtmlToPdfConverter::MAX_IMAGE_BYTES + 1
                            ),
                        ],
                        'unused',
                    ),
                ]),
            ]),
            static fn (string $host): array => ['93.184.216.34'],
        );

        self::assertStringStartsWith('%PDF-', $pdf);
        self::assertStringNotContainsString('/Subtype /Image', $pdf);
    }

    /**
     * Extracts the PDF's embedded FontFile2 stream (the CID-keyed
     * TrueType font program dompdf embeds for CJK text) and parses
     * it with php-font-lib, the same library dompdf itself uses to
     * process fonts. This is only valid for the single-page, single
     * embedded font PDFs this converter produces in these tests.
     */
    private function loadEmbeddedFontFile2(string $pdf): \FontLib\TrueType\File
    {
        self::assertMatchesRegularExpression(
            '/\/FontFile2 (\d+) 0 R/',
            $pdf,
            'PDF does not reference an embedded FontFile2 stream.',
        );
        preg_match('/\/FontFile2 (\d+) 0 R/', $pdf, $referenceMatch);
        $objectNumber = $referenceMatch[1];

        $found = preg_match(
            '/' . preg_quote($objectNumber, '/')
                . ' 0 obj\s*<<(.*?)>>\s*stream\r?\n/s',
            $pdf,
            $objectMatch,
            PREG_OFFSET_CAPTURE,
        );
        self::assertSame(
            1,
            $found,
            'Font stream object was not found in the PDF.',
        );

        $dictionary = $objectMatch[1][0];
        $streamStart = $objectMatch[0][1] + strlen($objectMatch[0][0]);

        self::assertMatchesRegularExpression(
            '/\/Length (\d+)/',
            $dictionary,
        );
        preg_match('/\/Length (\d+)/', $dictionary, $lengthMatch);
        $rawStream = substr($pdf, $streamStart, (int) $lengthMatch[1]);

        if (str_contains($dictionary, '/FlateDecode')) {
            $rawStream = gzuncompress($rawStream);
            self::assertIsString(
                $rawStream,
                'Embedded font stream failed to inflate.',
            );
        }

        $tempPath = tempnam(sys_get_temp_dir(), 'bvlion-font-test-');
        self::assertIsString($tempPath);

        try {
            file_put_contents($tempPath, $rawStream);
            $font = Font::load($tempPath);
        } finally {
            @unlink($tempPath);
        }

        self::assertInstanceOf(\FontLib\TrueType\File::class, $font);

        return $font;
    }
}
