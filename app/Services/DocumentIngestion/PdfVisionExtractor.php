<?php

namespace App\Services\DocumentIngestion;

use Spatie\PdfToImage\Enums\OutputFormat;
use Spatie\PdfToImage\Pdf;

class PdfVisionExtractor
{
    public function __construct(private readonly VisionTranscriber $transcriber) {}

    /**
     * FR-005: розпізнає текстовий вміст сторінки PDF (переважно зображення/скан)
     * через vision-модель, коли текстовий шар відсутній або недостатній.
     */
    public function extractPageText(string $absolutePath, int $pageNumber): string
    {
        $tempImage = tempnam(sys_get_temp_dir(), 'rag_page_').'.jpg';

        try {
            (new Pdf($absolutePath))
                ->format(OutputFormat::Jpg)
                ->selectPages($pageNumber)
                ->save($tempImage);

            return $this->transcriber->transcribe($tempImage);
        } finally {
            if (file_exists($tempImage)) {
                unlink($tempImage);
            }
        }
    }
}
