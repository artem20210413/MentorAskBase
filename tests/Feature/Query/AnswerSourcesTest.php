<?php

namespace Tests\Feature\Query;

use App\Models\Document;
use App\Models\DocumentChunk;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Responses\Chat\CreateResponse;
use OpenAI\Responses\Embeddings\CreateResponse as EmbeddingsCreateResponse;
use Tests\TestCase;

class AnswerSourcesTest extends TestCase
{
    public function test_answer_includes_source_document_name(): void
    {
        $this->authenticateApiClient();

        $dimensions = config('rag.openai.embedding_dimensions', 1536);
        $document = Document::factory()->create(['status' => 'processed', 'original_name' => 'policy.pdf']);
        DocumentChunk::factory()->for($document)->create([
            'embedding' => array_fill(0, $dimensions, 0.1),
        ]);

        OpenAI::fake([
            EmbeddingsCreateResponse::fake(['data' => [['embedding' => array_fill(0, $dimensions, 0.1)]]]),
            CreateResponse::fake(['choices' => [['message' => ['role' => 'assistant', 'content' => 'Відповідь із джерела.']]]]),
        ]);

        $response = $this->postJson('/api/v1/queries', ['question' => 'Питання по документу?']);

        $response->assertOk();
        $response->assertJsonCount(1, 'sources');
        $response->assertJsonPath('sources.0.document_id', $document->id);
        $response->assertJsonPath('sources.0.document_name', 'policy.pdf');
    }
}
