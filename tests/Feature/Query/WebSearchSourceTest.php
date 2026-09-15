<?php

namespace Tests\Feature\Query;

use App\Models\Document;
use App\Models\DocumentChunk;
use App\Services\Rag\KnowledgeBaseSearchTool;
use Illuminate\Support\Str;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Resources\Responses;
use OpenAI\Responses\Embeddings\CreateResponse as EmbeddingsCreateResponse;
use Tests\Support\FakesAgentResponses;
use Tests\TestCase;

class WebSearchSourceTest extends TestCase
{
    use FakesAgentResponses;

    public function test_question_without_relevant_documents_uses_web_search(): void
    {
        $this->authenticateApiClient();

        OpenAI::fake([
            $this->fakeWebSearchAnswer(
                'Останні новини компанії Mentor.',
                'новини Mentor',
                [['url' => 'https://www.jnjmedtech.com/news', 'title' => 'Mentor News']]
            ),
        ]);

        $response = $this->postJson('/api/v1/queries', ['question' => 'Які останні новини компанії Mentor?']);

        $response->assertOk();
        $response->assertJsonPath('sources.0.type', 'web');
        $response->assertJsonPath('sources.0.url', 'https://www.jnjmedtech.com/news');
        $this->assertNotEmpty($response->json('answer'));

        OpenAI::assertSent(Responses::class, function (string $method, array $params) {
            $toolTypes = collect($params['tools'] ?? [])->pluck('type');

            return $method === 'create' && $toolTypes->contains('web_search');
        });
    }

    public function test_question_fully_covered_by_knowledge_base_does_not_trigger_web_search(): void
    {
        $this->authenticateApiClient();

        $dimensions = config('rag.openai.embedding_dimensions', 1536);
        $document = Document::factory()->create(['status' => 'processed', 'original_name' => 'warranty.pdf']);
        DocumentChunk::factory()->for($document)->create([
            'content' => 'Гарантія на виріб X становить 24 місяці.',
            'embedding' => array_fill(0, $dimensions, 0.1),
        ]);

        OpenAI::fake([
            $this->fakeFunctionToolCall('call_1', KnowledgeBaseSearchTool::NAME, ['query' => 'Яка гарантія на виріб X?']),
            EmbeddingsCreateResponse::fake(['data' => [['embedding' => array_fill(0, $dimensions, 0.1)]]]),
            $this->fakeFinalAnswer('Гарантія становить 24 місяці.'),
        ]);

        $response = $this->postJson('/api/v1/queries', ['question' => 'Яка гарантія на виріб X?']);

        $response->assertOk();
        $response->assertJsonCount(1, 'sources');
        $response->assertJsonPath('sources.0.type', 'document');
    }

    public function test_no_useful_web_results_returns_honest_no_info_answer(): void
    {
        $this->authenticateApiClient();

        OpenAI::fake([
            $this->fakeFinalAnswer(__('bot.no_relevant_info_marker')),
        ]);

        $response = $this->postJson('/api/v1/queries', ['question' => 'Питання, на яке ніде немає відповіді?']);

        $response->assertOk();
        $response->assertJsonPath('sources', []);
        $this->assertNotEmpty($response->json('answer'));
    }

    /**
     * FR-010/FR-012: мова системних інструкцій (config('app.locale'),
     * наразі завжди англійська) і мова відповіді користувачу (за мовою
     * питання) — дві незалежні одна від одної речі.
     */
    public function test_system_instructions_stay_english_regardless_of_question_language(): void
    {
        $this->assertSame('en', config('app.locale'));

        $this->authenticateApiClient();

        OpenAI::fake([
            $this->fakeFinalAnswer('Гарантія становить 24 місяці.'),
        ]);

        $response = $this->postJson('/api/v1/queries', ['question' => 'Яка гарантія на виріб X?']);

        $response->assertOk();
        $response->assertJsonPath('answer_language', 'uk');

        OpenAI::assertSent(Responses::class, function (string $method, array $params) {
            return $method === 'create' && Str::contains($params['instructions'] ?? '', 'specialized assistant for MENTOR');
        });
    }
}
