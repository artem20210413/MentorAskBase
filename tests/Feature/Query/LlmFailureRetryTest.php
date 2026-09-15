<?php

namespace Tests\Feature\Query;

use Exception;
use OpenAI\Laravel\Facades\OpenAI;
use Tests\Support\FakesAgentResponses;
use Tests\TestCase;

class LlmFailureRetryTest extends TestCase
{
    use FakesAgentResponses;

    public function test_single_retry_then_success(): void
    {
        $this->authenticateApiClient();

        // FR-009c: рівно одна повторна спроба навколо всього агентного
        // циклу — перша спроба провалюється одразу на першому зверненні
        // до LLM, друга — успішна.
        OpenAI::fake([
            new Exception('Тимчасова недоступність LLM'),
            $this->fakeFinalAnswer('Відповідь після ретраю.'),
        ]);

        $response = $this->postJson('/api/v1/queries', ['question' => 'Питання?']);

        $response->assertOk();
        $response->assertJsonPath('answer', 'Відповідь після ретраю.');
    }

    public function test_failure_after_retry_returns_clear_error(): void
    {
        $this->authenticateApiClient();

        OpenAI::fake([
            new Exception('Перший збій'),
            new Exception('Другий збій (після ретраю)'),
        ]);

        $response = $this->postJson('/api/v1/queries', ['question' => 'Питання?']);

        $response->assertStatus(502);
        $this->assertDatabaseCount('query_logs', 0);
    }
}
