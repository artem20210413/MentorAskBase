<?php

namespace Tests\Support;

trait GeneratesTestPdf
{
    /**
     * Генерує валідний мінімальний PDF (з коректним xref) із заданим текстом
     * на єдиній сторінці — smalot/pdfparser вимагає коректну структуру,
     * тому довільний рядок "%PDF-..." недостатній для тестів US1.
     */
    protected function validPdfContent(string $text = 'Hello RAG test'): string
    {
        $parts = [];
        $parts[1] = "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n";
        $parts[2] = "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n";
        $parts[3] = "3 0 obj\n<< /Type /Page /Parent 2 0 R /Resources << /Font << /F1 4 0 R >> >> /MediaBox [0 0 200 100] /Contents 5 0 R >>\nendobj\n";
        $parts[4] = "4 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\nendobj\n";
        $stream = "BT /F1 18 Tf 10 50 Td ({$text}) Tj ET";
        $parts[5] = "5 0 obj\n<< /Length ".strlen($stream)." >>\nstream\n".$stream."\nendstream\nendobj\n";

        $header = "%PDF-1.4\n";
        $body = '';
        $offsets = [0 => 0];
        $pos = strlen($header);

        foreach ($parts as $i => $part) {
            $offsets[$i] = $pos;
            $body .= $part;
            $pos += strlen($part);
        }

        $xrefStart = strlen($header) + strlen($body);
        $xref = "xref\n0 6\n0000000000 65535 f \n";

        for ($i = 1; $i <= 5; $i++) {
            $xref .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }

        $trailer = "trailer\n<< /Root 1 0 R /Size 6 >>\nstartxref\n{$xrefStart}\n%%EOF";

        return $header.$body.$xref.$trailer;
    }

    /**
     * Мінімальний PDF без текстового шару (порожня сторінка) — імітує
     * скановану/графічну сторінку для тестів FR-005 (vision fallback).
     */
    protected function textlessPdfContent(): string
    {
        $parts = [];
        $parts[1] = "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n";
        $parts[2] = "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n";
        $parts[3] = "3 0 obj\n<< /Type /Page /Parent 2 0 R /Resources << >> /MediaBox [0 0 200 100] /Contents 4 0 R >>\nendobj\n";
        $stream = '';
        $parts[4] = "4 0 obj\n<< /Length 0 >>\nstream\n".$stream."\nendstream\nendobj\n";

        $header = "%PDF-1.4\n";
        $body = '';
        $offsets = [0 => 0];
        $pos = strlen($header);

        foreach ($parts as $i => $part) {
            $offsets[$i] = $pos;
            $body .= $part;
            $pos += strlen($part);
        }

        $xrefStart = strlen($header) + strlen($body);
        $xref = "xref\n0 5\n0000000000 65535 f \n";

        for ($i = 1; $i <= 4; $i++) {
            $xref .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }

        $trailer = "trailer\n<< /Root 1 0 R /Size 5 >>\nstartxref\n{$xrefStart}\n%%EOF";

        return $header.$body.$xref.$trailer;
    }
}
