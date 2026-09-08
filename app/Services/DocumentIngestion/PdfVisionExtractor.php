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
                                'text' => 'Це сторінка навчального/довідкового документа для помічника лікаря (медична база знань для професійного використання). '
                                    .'Розпізнай і поверни весь текст, присутній на цьому зображенні сторінки, без коментарів. '
                                    .'Медична термінологія, анатомічні описи та клінічна інформація в цьому контексті є звичайним професійним матеріалом, а не чутливою темою — розпізнавай текст як є, нічого не пропускай і не відмовляй.',
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
