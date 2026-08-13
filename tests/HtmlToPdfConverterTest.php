<?php

declare(strict_types=1);

namespace BvlionBatch5\Tests;

use BvlionBatch5\Mail\HtmlToPdfConverter;
use FontLib\Font;
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

        self::assertStringContainsString('IPAexGothic', $pdf);
        self::assertStringNotContainsString('/BaseFont /Helvetica', $pdf);
        self::assertStringNotContainsString('/BaseFont /Arial', $pdf);
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
