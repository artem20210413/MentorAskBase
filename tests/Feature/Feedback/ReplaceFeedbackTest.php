<?php

namespace Tests\Feature\Feedback;

use App\Models\QueryLog;
use Tests\TestCase;

class ReplaceFeedbackTest extends TestCase
{
    public function test_new_feedback_replaces_previous_one(): void
    {
        $this->authenticateApiClient();

        $log = QueryLog::factory()->create();

        $this->postJson("/api/v1/queries/{$log->id}/feedback", ['rating' => 'useful'])->assertOk();
        $response = $this->postJson("/api/v1/queries/{$log->id}/feedback", ['rating' => 'not_useful']);

        $response->assertOk();
        $response->assertJsonPath('rating', 'not_useful');
        $this->assertDatabaseCount('answer_feedback', 1);
        $this->assertDatabaseHas('answer_feedback', [
            'query_log_id' => $log->id,
            'rating' => 'not_useful',
        ]);
    }
}
