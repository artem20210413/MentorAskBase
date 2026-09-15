<?php

namespace Tests\Unit;

use App\Models\Document;
use App\Models\DocumentChunk;
use App\Services\Rag\EmbeddingService;
use App\Services\Rag\KnowledgeBaseSearchTool;
use App\Services\Rag\VectorSearchService;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Responses\Embeddings\CreateResponse as EmbeddingsCreateResponse;
use Tests\TestCase;

class KnowledgeBaseSearchToolTest extends TestCase
{
    public function test_schema_declares_function_tool_with_query_parameter(): void
    {
        $tool = new KnowledgeBaseSearchTool(new EmbeddingService, new VectorSearchService);

        $schema = $tool->schema();

        $this->assertSame('function', $schema['type']);
        $this->assertSame(KnowledgeBaseSearchTool::NAME, $schema['name']);
        $this->assertArrayHasKey('query', $schema['parameters']['properties']);
        $this->assertNotEmpty($schema['description']);
    }

    public function test_execute_returns_normalized_document_sources(): void
    {
        $dimensions = config('rag.openai.embedding_dimensions', 1536);

        $document = Document::factory()->create(['status' => 'processed', 'original_name' => 'manual.pdf']);
        DocumentChunk::factory()->for($document)->create([
            'page_number' => 2,
            'content' => 'Гарантія становить 24 місяці.',
            'embedding' => array_fill(0, $dimensions, 0.1),
        ]);

        OpenAI::fake([
            EmbeddingsCreateResponse::fake(['data' => [['embedding' => array_fill(0, $dimensions, 0.1)]]]),
        ]);

        $tool = new KnowledgeBaseSearchTool(new EmbeddingService, new VectorSearchService);
        $results = $tool->execute('Яка гарантія?');

        $this->assertCount(1, $results);
        $this->assertSame('document', $results[0]['type']);
        $this->assertSame($document->id, $results[0]['document_id']);
        $this->assertSame('manual.pdf', $results[0]['document_name']);
        $this->assertSame(2, $results[0]['page_number']);
        $this->assertSame(100, $results[0]['relevance']);
        $this->assertSame('Гарантія становить 24 місяці.', $results[0]['content']);
    }

    public function test_execute_returns_empty_array_when_nothing_relevant(): void
    {
        $dimensions = config('rag.openai.embedding_dimensions', 1536);

        OpenAI::fake([
            EmbeddingsCreateResponse::fake(['data' => [['embedding' => array_fill(0, $dimensions, 0.1)]]]),
        ]);

        $tool = new KnowledgeBaseSearchTool(new EmbeddingService, new VectorSearchService);

        $this->assertSame([], $tool->execute('Немає нічого релевантного'));
    }
}
