<?php

declare(strict_types=1);

namespace BvlionBatch5\Mail;

use Dompdf\Dompdf;
use Dompdf\Options;
use RuntimeException;
use Throwable;

final class HtmlToPdfConverter
{
    public function convert(string $html): string
    {
        $options = new Options();
        $options->setIsRemoteEnabled(false);
        $options->setIsJavascriptEnabled(false);
        $options->setIsHtml5ParserEnabled(true);

        $dompdf = new Dompdf($options);

        try {
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
}
