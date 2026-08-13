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
     * Agreement v1.0, see the license file there), instead of
     * relying on XServer's installed fonts. Registered under
     * OVERRIDDEN_FONT_FAMILIES below so it is the only font Dompdf
     * can ever select, regardless of what the mail's own font-family
     * declares: Dompdf, unlike a browser, does not fall back to a
     * different font per glyph when the selected font is missing
     * characters, so letting an unregistered or non-CJK font win
     * would silently render Japanese text as blank glyphs.
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

    /**
     * Every font family name Dompdf itself ships in
     * lib/fonts/installed-fonts.dist.json. A mail's own CSS cannot
     * make Dompdf select a non-Japanese font by using `!important`
     * or a more specific selector, because these are the only family
     * names Dompdf's FontMetrics::getFont() can ever resolve to
     * something other than Options::defaultFont() (itself set to
     * FONT_FAMILY below) -- overriding all of them, in addition to
     * FONT_FAMILY, removes every non-Japanese font Dompdf could
     * possibly pick. Any other family name a mail requests simply
     * fails to resolve and falls through to defaultFont(), per
     * Css\Style::_get_font_family(). This is verified by
     * HtmlToPdfConverterTest against Dompdf's actual source
     * (vendor/dompdf/dompdf), not merely by observed behavior.
     */
    private const OVERRIDDEN_FONT_FAMILIES = [
        self::FONT_FAMILY,
        'sans-serif',
        'serif',
        'monospace',
        'fixed',
        'times',
        'times-roman',
        'courier',
        'helvetica',
        'symbol',
        'zapfdingbats',
        'dejavu sans',
        'dejavu sans mono',
        'dejavu serif',
    ];

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
            $dompdf->loadHtml($html, 'UTF-8');
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
     * goal (see the FONT_FAMILY docblock). Registering it under
     * every name in OVERRIDDEN_FONT_FAMILIES -- rather than fighting
     * the mail's own CSS cascade with an injected override rule --
     * is what actually guarantees the mail's own font-family cannot
     * defeat this, including with `!important` or a highly specific
     * selector: whichever family name Dompdf ends up resolving, it
     * resolves to this file.
     */
    private function registerJapaneseFont(Dompdf $dompdf): void
    {
        $fontMetrics = $dompdf->getFontMetrics();

        foreach (self::OVERRIDDEN_FONT_FAMILIES as $family) {
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
                        'family' => $family,
                        'weight' => $variant['weight'],
                        'style' => $variant['style'],
                    ],
                    self::FONT_PATH,
                );
            }
        }
    }
}
