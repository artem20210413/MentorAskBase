<?php

namespace App\Services\DocumentIngestion;

use OpenAI\Laravel\Facades\OpenAI;
use Spatie\PdfToImage\Enums\OutputFormat;
use Spatie\PdfToImage\Pdf;

class PdfVisionExtractor
{
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

            $base64 = base64_encode(file_get_contents($tempImage));

            $response = OpenAI::chat()->create([
                'model' => config('rag.openai.vision_model'),
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => [
                            [
                                'type' => 'text',
                                'text' => 'This is a page from an educational/reference document for a doctor\'s assistant (professional medical knowledge base). '
                                    .'Transcribe and return all text present on this page image, with no commentary. '
                                    .'Medical terminology, anatomical descriptions, and clinical information in this context are ordinary professional material, not a sensitive topic — transcribe the text as-is, don\'t skip anything, and don\'t refuse.',
                            ],
                            [
                                'type' => 'image_url',
                                'image_url' => ['url' => "data:image/jpeg;base64,{$base64}"],
                            ],
                        ],
                    ],
                ],
            ]);

            return trim($response->choices[0]->message->content ?? '');
        } finally {
            if (file_exists($tempImage)) {
                unlink($tempImage);
            }
        }
    }
}
