<?php

namespace Tests\Unit;

use App\Services\Rag\QueryRewriter;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Resources\Chat;
use OpenAI\Responses\Chat\CreateResponse;
use Tests\TestCase;

class QueryRewriterTest extends TestCase
{
    public function test_returns_question_unchanged_when_history_is_empty(): void
    {
        OpenAI::fake();

        $result = (new QueryRewriter)->rewriteForRetrieval('Яка гарантія на виріб X?', []);

        $this->assertSame('Яка гарантія на виріб X?', $result);
        OpenAI::assertNotSent(Chat::class);
    }

    public function test_rewrites_question_using_history_when_present(): void
    {
        OpenAI::fake([
            CreateResponse::fake(['choices' => [['message' => ['role' => 'assistant', 'content' => 'Яка гарантія на виріб Y?']]]]),
        ]);

        $history = [
            ['role' => 'user', 'content' => 'Яка гарантія на виріб X?'],
            ['role' => 'assistant', 'content' => 'Гарантія на X — 24 місяці.'],
        ];

        $result = (new QueryRewriter)->rewriteForRetrieval('А другий?', $history);

        $this->assertSame('Яка гарантія на виріб Y?', $result);
    }

    public function test_falls_back_to_original_question_on_llm_failure(): void
    {
        OpenAI::fake([
            new \Exception('LLM недоступна'),
        ]);

        $result = (new QueryRewriter)->rewriteForRetrieval('А другий?', [
            ['role' => 'user', 'content' => 'Питання'],
            ['role' => 'assistant', 'content' => 'Відповідь'],
        ]);

        $this->assertSame('А другий?', $result);
    }
}
