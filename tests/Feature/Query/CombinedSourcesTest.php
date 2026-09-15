<?php

namespace Tests\Feature\Query;

use App\Models\Document;
use App\Models\DocumentChunk;
use App\Services\Rag\KnowledgeBaseSearchTool;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Responses\Embeddings\CreateResponse as EmbeddingsCreateResponse;
use Tests\Support\FakesAgentResponses;
use Tests\TestCase;

class CombinedSourcesTest extends TestCase
{
    use FakesAgentResponses;

    public function test_question_needing_both_knowledge_base_and_web_search_combines_both_source_types(): void
    {
        $this->authenticateApiClient();

        $dimensions = config('rag.openai.embedding_dimensions', 1536);
        $document = Document::factory()->create(['status' => 'processed', 'original_name' => 'internal-policy.pdf']);
        DocumentChunk::factory()->for($document)->create([
            'content' => 'Внутрішня гарантійна політика: 24 місяці стандартно.',
            'embedding' => array_fill(0, $dimensions, 0.1),
        ]);

        OpenAI::fake([
            // Крок 1: модель спершу перевіряє базу знань
            $this->fakeFunctionToolCall('call_1', KnowledgeBaseSearchTool::NAME, ['query' => 'Гарантія та актуальні публічні дані виробника?']),
            EmbeddingsCreateResponse::fake(['data' => [['embedding' => array_fill(0, $dimensions, 0.1)]]]),
            // Крок 2: цієї інформації недостатньо — модель також шукає в інтернеті
            $this->fakeWebSearchAnswer(
                'За внутрішньою політикою гарантія 24 місяці; за офіційними даними виробника — те саме.',
                'офіційна гарантійна політика MENTOR',
                [['url' => 'https://www.jnjmedtech.com/warranty', 'title' => 'MENTOR Warranty']]
            ),
        ]);

        $response = $this->postJson('/api/v1/queries', ['question' => 'Гарантія та актуальні публічні дані виробника?']);

        $response->assertOk();
        $types = collect($response->json('sources'))->pluck('type');

        $this->assertTrue($types->contains('document'));
        $this->assertTrue($types->contains('web'));
    }

    public function test_question_fully_covered_by_knowledge_base_skips_web_search(): void
    {
        $this->authenticateApiClient();

        $dimensions = config('rag.openai.embedding_dimensions', 1536);
        $document = Document::factory()->create(['status' => 'processed']);
        DocumentChunk::factory()->for($document)->create([
            'embedding' => array_fill(0, $dimensions, 0.1),
        ]);

        OpenAI::fake([
            $this->fakeFunctionToolCall('call_1', KnowledgeBaseSearchTool::NAME, ['query' => 'Питання?']),
            EmbeddingsCreateResponse::fake(['data' => [['embedding' => array_fill(0, $dimensions, 0.1)]]]),
            $this->fakeFinalAnswer('Відповідь лише з бази знань.'),
        ]);

        $response = $this->postJson('/api/v1/queries', ['question' => 'Питання?']);

        $response->assertOk();
        $types = collect($response->json('sources'))->pluck('type');

        $this->assertFalse($types->contains('web'));
    }
}
