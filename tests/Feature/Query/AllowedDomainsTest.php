<?php

namespace Tests\Feature\Query;

use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Resources\Responses;
use Tests\Support\FakesAgentResponses;
use Tests\TestCase;

class AllowedDomainsTest extends TestCase
{
    use FakesAgentResponses;

    public function test_web_search_tool_includes_allowed_domains_filter_when_configured(): void
    {
        config(['rag.web_search.allowed_domains' => ['jnjmedtech.com', 'mentorwwllc.com']]);
        $this->authenticateApiClient();

        OpenAI::fake([
            $this->fakeWebSearchAnswer('Відповідь з дозволеного сайту.', 'запит', [
                ['url' => 'https://www.jnjmedtech.com/x', 'title' => 'X'],
            ]),
        ]);

        $response = $this->postJson('/api/v1/queries', ['question' => 'Питання, що вимагає пошуку?']);

        $response->assertOk();

        OpenAI::assertSent(Responses::class, function (string $method, array $params) {
            $webSearchTool = collect($params['tools'] ?? [])->firstWhere('type', 'web_search');

            return $method === 'create'
                && $webSearchTool !== null
                && ($webSearchTool['filters']['allowed_domains'] ?? null) === ['jnjmedtech.com', 'mentorwwllc.com'];
        });
    }

    public function test_web_search_tool_has_no_domain_restriction_when_list_is_empty(): void
    {
        config(['rag.web_search.allowed_domains' => []]);
        $this->authenticateApiClient();

        OpenAI::fake([
            $this->fakeWebSearchAnswer('Відповідь без обмежень.', 'запит'),
        ]);

        $response = $this->postJson('/api/v1/queries', ['question' => 'Питання, що вимагає пошуку?']);

        $response->assertOk();

        OpenAI::assertSent(Responses::class, function (string $method, array $params) {
            $webSearchTool = collect($params['tools'] ?? [])->firstWhere('type', 'web_search');

            return $method === 'create'
                && $webSearchTool !== null
                && ! array_key_exists('filters', $webSearchTool);
        });
    }
}
