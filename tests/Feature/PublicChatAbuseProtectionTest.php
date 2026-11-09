<?php

namespace Tests\Feature;

use App\Livewire\PublicChat;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use OpenAI\Laravel\Facades\OpenAI;
use Tests\Support\FakesAgentResponses;
use Tests\TestCase;

/**
 * Публічний чат без авторизації: питання надсилаються через Livewire
 * (/livewire/update), тож throttle на GET /chat їх не обмежує. Ліміт і
 * валідація мають бути всередині компонента, інакше будь-хто може
 * безкоштовно для себе витрачати гроші на OpenAI.
 */
class PublicChatAbuseProtectionTest extends TestCase
{
    use FakesAgentResponses;

    public function test_questions_over_rate_limit_are_rejected_without_calling_llm(): void
    {
        config([
            'rag.rate_limit_per_minute' => 2,
            // без додаткового LLM-виклику QueryRewriter для другого питання сесії
            'rag.llm.query_refinement_enabled' => false,
        ]);

        OpenAI::fake([
            $this->fakeFinalAnswer('Відповідь 1'),
            $this->fakeFinalAnswer('Відповідь 2'),
        ]);

        $component = Livewire::test(PublicChat::class)
            ->set('question', 'Питання 1')->call('ask')
            ->set('question', 'Питання 2')->call('ask')
            ->set('question', 'Питання 3')->call('ask');

        $component->assertSet('error', 'Забагато запитів. Спробуйте за хвилину.');
        $this->assertDatabaseCount('query_logs', 2);
    }

    public function test_too_long_question_is_rejected_without_calling_llm(): void
    {
        OpenAI::fake([]);

        Livewire::test(PublicChat::class)
            ->set('question', str_repeat('а', 2001))
            ->call('ask')
            ->assertHasErrors(['question' => 'max']);

        $this->assertDatabaseCount('query_logs', 0);
    }

    public function test_session_id_cannot_be_set_from_client(): void
    {
        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::test(PublicChat::class)
            ->set('sessionId', '11111111-1111-4111-8111-111111111111');
    }
}
