<?php

declare(strict_types=1);

namespace BvlionBatch5\Mail;

use Dompdf\Dompdf;
use Dompdf\Options;
use RuntimeException;
use Throwable;

final class HtmlToPdfConverter
{
    /**
     * Bundled under resources/fonts/IPAexGothic (IPA Font License
     * Agreement v1.0, see the license file there). Registered
     * explicitly and forced onto every element below, instead of
     * relying on XServer's installed fonts or on Dompdf resolving a
     * CJK-capable font from the mail's own font-family declarations:
     * Dompdf, unlike a browser, does not fall back to a different
     * font per glyph when the selected font is missing characters,
     * so an unregistered or non-CJK font would silently render
     * Japanese text as blank glyphs.
     *
     * TrueType (.ttf), not OpenType/CFF (.otf), is required here:
     * dompdf/php-font-lib's CID font embedding only reliably embeds
     * the `glyf`-outline flavor. A CFF-outline .otf (e.g. Noto Sans
     * JP as distributed by Google Fonts / Noto CJK) registers and
     * "renders" without error, but produces a corrupt embedded font
     * program that most PDF viewers fail to parse, showing garbled
     * glyphs instead of the Japanese text.
     */
    private const FONT_FAMILY = 'IPAex Gothic';
    private const FONT_DIRECTORY = __DIR__
        . '/../../resources/fonts/IPAexGothic';
    private const FONT_PATH = self::FONT_DIRECTORY . '/ipaexg.ttf';

    public function convert(string $html): string
    {
        $options = new Options();
        $options->setIsRemoteEnabled(false);
        $options->setIsJavascriptEnabled(false);
        $options->setIsHtml5ParserEnabled(true);
        $options->setDefaultFont(self::FONT_FAMILY);
        // Dompdf's local-file access is restricted to its own chroot
        // (its package directory by default), so the bundled font
        // directory must be added explicitly or registerFont() below
        // fails silently and PDFs fall back to a non-CJK core font.
        $options->setChroot([self::FONT_DIRECTORY]);

        $dompdf = new Dompdf($options);

        try {
            $this->registerJapaneseFont($dompdf);
            $dompdf->loadHtml($this->forceJapaneseFont($html), 'UTF-8');
            $dompdf->setPaper('A4', 'portrait');
            $dompdf->render();
            $pdf = $dompdf->output();
        } catch (Throwable) {
            throw new RuntimeException('HTML to PDF conversion failed.');
        }

        if (!is_string($pdf) || $pdf === '') {
            throw new RuntimeException('HTML to PDF conversion failed.');
        }

        return $pdf;
    }

    /**
     * IPAex Gothic ships a single (regular) weight, so that same
     * file is registered for bold/italic variants too: Dompdf will
     * render them without a true bold/italic outline, but the
     * Japanese text stays readable, which is this feature's only
     * goal (see the FONT_FAMILY docblock).
     */
    private function registerJapaneseFont(Dompdf $dompdf): void
    {
        $fontMetrics = $dompdf->getFontMetrics();

        foreach (
            [
                ['weight' => 'normal', 'style' => 'normal'],
                ['weight' => 'bold', 'style' => 'normal'],
                ['weight' => 'normal', 'style' => 'italic'],
                ['weight' => 'bold', 'style' => 'italic'],
            ] as $variant
        ) {
            $fontMetrics->registerFont(
                [
                    'family' => self::FONT_FAMILY,
                    'weight' => $variant['weight'],
                    'style' => $variant['style'],
                ],
                self::FONT_PATH,
            );
        }
    }

    /**
     * Overrides every element's font-family with the registered
     * Japanese font, regardless of what the mail's own HTML/CSS
     * requests. This trades typographic fidelity with the original
     * mail for guaranteed readability of the Japanese text, which is
     * this feature's only goal (see the FONT_FAMILY docblock).
     */
    private function forceJapaneseFont(string $html): string
    {
        $style = sprintf(
            '<style>*,*::before,*::after{font-family:"%s",sans-serif '
                . '!important;}</style>',
            self::FONT_FAMILY,
        );

        if (stripos($html, '</head>') !== false) {
            return preg_replace('/<\/head>/i', $style . '</head>', $html, 1);
        }

        return $style . $html;
    }
}
