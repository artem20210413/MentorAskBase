<?php

namespace Tests\Feature\Query;

use App\Models\ConversationSession;
use App\Models\QueryLog;
use Tests\TestCase;

class QueryLogListTest extends TestCase
{
    public function test_query_log_can_be_reviewed_without_re_calling_llm(): void
    {
        $this->authenticateApiClient();

        $session = ConversationSession::factory()->create();
        $log = QueryLog::factory()->for($session, 'conversationSession')->create([
            'question' => 'Раніше поставлене питання?',
            'answer' => 'Раніше отримана відповідь.',
        ]);

        $response = $this->getJson('/api/v1/queries');

        $response->assertOk();
        $response->assertJsonPath('data.0.query_log_id', $log->id);
        $response->assertJsonPath('data.0.question', 'Раніше поставлене питання?');
        $response->assertJsonPath('data.0.answer', 'Раніше отримана відповідь.');
        $response->assertJsonMissingPath('data.0.llm_input_tokens');
    }
}
