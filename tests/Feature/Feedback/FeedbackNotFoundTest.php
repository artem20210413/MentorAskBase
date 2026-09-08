<?php

namespace Tests\Feature\Feedback;

use Illuminate\Support\Str;
use Tests\TestCase;

class FeedbackNotFoundTest extends TestCase
{
    public function test_feedback_for_unknown_query_log_returns_404(): void
    {
        $this->authenticateApiClient();

        $response = $this->postJson('/api/v1/queries/'.Str::uuid().'/feedback', ['rating' => 'useful']);

        $response->assertNotFound();
    }
}
