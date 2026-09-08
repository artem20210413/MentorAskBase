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
                                'text' => 'Transcribe all text on this document page image, exactly as written. '
                                    .'Then, if the page contains meaningful images, diagrams, charts, or tables (not just decorative elements), '
                                    .'add a short section "[Image description]" describing what they show. No other commentary.',
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
