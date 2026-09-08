<?php

namespace Tests\Unit;

use App\Services\DocumentIngestion\PdfVisionExtractor;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Resources\Chat;
use OpenAI\Responses\Chat\CreateResponse;
use Tests\Support\GeneratesTestPdf;
use Tests\TestCase;

class PdfVisionExtractorTest extends TestCase
{
    use GeneratesTestPdf;

    public function test_extract_page_text_calls_vision_model_and_returns_recognized_text(): void
    {
        OpenAI::fake([
            CreateResponse::fake([
                'choices' => [
                    ['message' => ['role' => 'assistant', 'content' => 'Розпізнаний текст зі сторінки']],
                ],
            ]),
        ]);

        $path = tempnam(sys_get_temp_dir(), 'rag_test_').'.pdf';
        file_put_contents($path, $this->textlessPdfContent());

        $text = (new PdfVisionExtractor)->extractPageText($path, 1);

        $this->assertSame('Розпізнаний текст зі сторінки', $text);

        OpenAI::assertSent(Chat::class, function (string $method, array $params) {
            return $method === 'create' && $params['model'] === config('rag.openai.vision_model');
        });

        unlink($path);
    }
}
