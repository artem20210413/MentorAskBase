<?php

namespace Tests\Feature\Feedback;

use App\Models\QueryLog;
use Tests\TestCase;

class SubmitFeedbackTest extends TestCase
{
    public function test_feedback_with_comment_is_saved(): void
    {
        $this->authenticateApiClient();

        $log = QueryLog::factory()->create();

        $response = $this->postJson("/api/v1/queries/{$log->id}/feedback", [
            'rating' => 'useful',
            'comment' => 'Дуже корисна відповідь',
        ]);

        $response->assertOk();
        $response->assertJsonPath('rating', 'useful');
        $this->assertDatabaseHas('answer_feedback', [
            'query_log_id' => $log->id,
            'rating' => 'useful',
            'comment' => 'Дуже корисна відповідь',
        ]);
    }
}
