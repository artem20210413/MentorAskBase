<?php

namespace Tests\Unit;

use App\Services\DocumentIngestion\VisionTranscriber;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Resources\Chat;
use OpenAI\Responses\Chat\CreateResponse;
use Tests\TestCase;

class VisionTranscriberTest extends TestCase
{
    public function test_transcribe_calls_vision_model_and_returns_recognized_text(): void
    {
        OpenAI::fake([
            CreateResponse::fake([
                'choices' => [
                    ['message' => ['role' => 'assistant', 'content' => 'Розпізнаний текст із зображення']],
                ],
            ]),
        ]);

        $path = tempnam(sys_get_temp_dir(), 'rag_test_').'.jpg';
        file_put_contents($path, 'fake-image-bytes');

        $text = (new VisionTranscriber)->transcribe($path);

        $this->assertSame('Розпізнаний текст із зображення', $text);

        OpenAI::assertSent(Chat::class, function (string $method, array $params) {
            return $method === 'create' && $params['model'] === config('rag.openai.vision_model');
        });

        unlink($path);
    }

    public function test_looks_like_refusal_detects_short_refusal_phrases(): void
    {
        $transcriber = new VisionTranscriber;

        $this->assertTrue($transcriber->looksLikeRefusal("I'm sorry, I can't help with that."));
        $this->assertTrue($transcriber->looksLikeRefusal('Sorry, I cannot process this image.'));
    }

    public function test_looks_like_refusal_ignores_real_transcription(): void
    {
        $transcriber = new VisionTranscriber;

        $this->assertFalse($transcriber->looksLikeRefusal('Розпізнаний текст зі сторінки з реальним вмістом документа.'));
        $this->assertFalse($transcriber->looksLikeRefusal(str_repeat('Довгий реальний текст. ', 20)));
    }
}
