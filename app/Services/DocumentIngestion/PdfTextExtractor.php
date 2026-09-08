<?php

namespace App\Services\DocumentIngestion;

use Smalot\PdfParser\Parser;
use Throwable;

class PdfTextExtractor
{
    /**
     * Використовується лише для визначення кількості сторінок документа —
     * сам контент сторінок розпізнається через vision-модель
     * (`PdfVisionExtractor`), а не з цього текстового шару (FR-005): на
     * складних макетах (інфографіка, колонки) текстовий шар PDF втрачає
     * візуальний порядок читання.
     *
     * @return array<int, string>
     */
    public function extract(string $absolutePath): array
    {
        $parser = new Parser;
        $pdf = $parser->parseFile($absolutePath);

        $pages = [];
        foreach ($pdf->getPages() as $page) {
            $pages[] = trim($page->getText());
        }

        return $pages;
    }

    /**
     * FR-001b: синхронна перевірка цілісності/формату файлу під час завантаження.
     *
     * @throws \RuntimeException якщо файл пошкоджений або не є PDF, який вдається розпарсити
     */
    public function assertReadable(string $absolutePath): void
    {
        try {
            (new Parser)->parseFile($absolutePath);
        } catch (Throwable $e) {
            throw new \RuntimeException('Файл пошкоджений або має непідтримуваний формат: '.$e->getMessage(), previous: $e);
        }
    }
}
