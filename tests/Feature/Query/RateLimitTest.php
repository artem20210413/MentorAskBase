<?php

namespace Tests\Feature\Query;

use Tests\TestCase;

class RateLimitTest extends TestCase
{
    public function test_exceeding_rate_limit_returns_429_with_retry_after(): void
    {
        config(['rag.rate_limit_per_minute' => 2]);
        $this->authenticateApiClient();

        $this->getJson('/api/v1/documents')->assertOk();
        $this->getJson('/api/v1/documents')->assertOk();
        $response = $this->getJson('/api/v1/documents');

        $response->assertStatus(429);
        $this->assertNotNull($response->headers->get('Retry-After'));
    }
}
