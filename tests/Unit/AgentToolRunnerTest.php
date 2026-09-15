<?php

namespace Tests\Unit;

use App\Models\Document;
use App\Models\DocumentChunk;
use App\Services\Rag\AgentToolRunner;
use App\Services\Rag\EmbeddingService;
use App\Services\Rag\KnowledgeBaseSearchTool;
use App\Services\Rag\VectorSearchService;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Responses\Embeddings\CreateResponse as EmbeddingsCreateResponse;
use Tests\Support\FakesAgentResponses;
use Tests\TestCase;

class AgentToolRunnerTest extends TestCase
{
    use FakesAgentResponses;

    private function runner(): AgentToolRunner
    {
        return new AgentToolRunner(new KnowledgeBaseSearchTool(new EmbeddingService, new VectorSearchService));
    }

    public function test_run_calls_knowledge_base_tool_and_returns_final_answer_with_sources_and_steps(): void
    {
        $dimensions = config('rag.openai.embedding_dimensions', 1536);
        $document = Document::factory()->create(['status' => 'processed', 'original_name' => 'manual.pdf']);
        DocumentChunk::factory()->for($document)->create([
            'page_number' => 1,
            'embedding' => array_fill(0, $dimensions, 0.1),
        ]);

        OpenAI::fake([
            $this->fakeFunctionToolCall('call_1', KnowledgeBaseSearchTool::NAME, ['query' => 'Питання?']),
            EmbeddingsCreateResponse::fake(['data' => [['embedding' => array_fill(0, $dimensions, 0.1)]]]),
            $this->fakeFinalAnswer('Ось відповідь.'),
        ]);

        $result = $this->runner()->run('Instructions.', [['role' => 'user', 'content' => 'Питання?']]);

        $this->assertSame('Ось відповідь.', $result['answer']);
        $this->assertCount(1, $result['sources']);
        $this->assertSame('document', $result['sources'][0]['type']);
        $this->assertSame($document->id, $result['sources'][0]['document_id']);

        $this->assertCount(1, $result['tool_steps']);
        $this->assertSame('search_knowledge_base', $result['tool_steps'][0]['tool']);
        $this->assertSame('Питання?', $result['tool_steps'][0]['input']);
        $this->assertNotNull($result['tool_steps'][0]['output']);
    }

    public function test_run_returns_web_sources_from_url_citations(): void
    {
        OpenAI::fake([
            $this->fakeWebSearchAnswer(
                'Свіжа інформація з інтернету.',
                'останні новини',
                [['url' => 'https://example.com/news', 'title' => 'News']]
            ),
        ]);

        $result = $this->runner()->run('Instructions.', [['role' => 'user', 'content' => 'Що нового?']]);

        $this->assertSame('Свіжа інформація з інтернету.', $result['answer']);
        $this->assertCount(1, $result['sources']);
        $this->assertSame('web', $result['sources'][0]['type']);
        $this->assertSame('https://example.com/news', $result['sources'][0]['url']);
        $this->assertSame('News', $result['sources'][0]['title']);

        $this->assertCount(1, $result['tool_steps']);
        $this->assertSame('web_search', $result['tool_steps'][0]['tool']);
        $this->assertSame('останні новини', $result['tool_steps'][0]['input']);
    }

    public function test_run_stops_after_configured_max_steps(): void
    {
        config(['rag.agent.max_tool_steps' => 1]);

        // Цикл MUST зупинитися рівно після 1 кроку й примусово запитати
        // фінальну відповідь (tool_choice=none) на основі вже зібраного.
        OpenAI::fake([
            $this->fakeFunctionToolCall('call_1', KnowledgeBaseSearchTool::NAME, ['query' => 'Питання?']),
            EmbeddingsCreateResponse::fake(['data' => [['embedding' => array_fill(0, config('rag.openai.embedding_dimensions', 1536), 0.1)]]]),
            $this->fakeFinalAnswer('Примусова фінальна відповідь.'),
        ]);

        $result = $this->runner()->run('Instructions.', [['role' => 'user', 'content' => 'Питання?']]);

        $this->assertSame('Примусова фінальна відповідь.', $result['answer']);
        $this->assertCount(1, $result['tool_steps']);
    }

    public function test_run_recovers_when_web_search_call_fails_after_a_successful_step(): void
    {
        $dimensions = config('rag.openai.embedding_dimensions', 1536);
        $document = Document::factory()->create(['status' => 'processed']);
        DocumentChunk::factory()->for($document)->create([
            'embedding' => array_fill(0, $dimensions, 0.1),
        ]);

        OpenAI::fake([
            $this->fakeFunctionToolCall('call_1', KnowledgeBaseSearchTool::NAME, ['query' => 'Питання?']),
            EmbeddingsCreateResponse::fake(['data' => [['embedding' => array_fill(0, $dimensions, 0.1)]]]),
            new \Exception('web_search тимчасово недоступний'),
        ]);

        $result = $this->runner()->run('Instructions.', [['role' => 'user', 'content' => 'Питання?']]);

        // FR-008: збій другого кроку не провалює виконання — повертається
        // те, що вже встигли зібрати на першому кроці (search_knowledge_base).
        $this->assertCount(1, $result['sources']);
        $this->assertSame('document', $result['sources'][0]['type']);
    }
}
