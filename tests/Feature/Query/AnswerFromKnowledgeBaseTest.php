<?php

namespace Tests\Feature\Query;

use App\Models\Document;
use App\Models\DocumentChunk;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Responses\Chat\CreateResponse;
use OpenAI\Responses\Embeddings\CreateResponse as EmbeddingsCreateResponse;
use Tests\TestCase;

class AnswerFromKnowledgeBaseTest extends TestCase
{
    public function test_question_about_known_fact_returns_correct_answer_with_sources(): void
    {
        $this->authenticateApiClient();

        $document = Document::factory()->create(['status' => 'processed', 'original_name' => 'warranty.pdf']);
        $dimensions = config('rag.openai.embedding_dimensions', 1536);

        $chunk = DocumentChunk::factory()->for($document)->create([
            'content' => 'Гарантія на виріб X становить 24 місяці.',
            'embedding' => array_fill(0, $dimensions, 0.1),
        ]);

        OpenAI::fake([
            EmbeddingsCreateResponse::fake([
                'data' => [['embedding' => array_fill(0, $dimensions, 0.1)]],
            ]),
            CreateResponse::fake([
                'choices' => [
                    ['message' => ['role' => 'assistant', 'content' => 'Гарантія становить 24 місяці.']],
                ],
            ]),
        ]);

        $response = $this->postJson('/api/v1/queries', ['question' => 'Яка гарантія на виріб X?']);

        $response->assertOk();
        $response->assertJsonPath('answer', 'Гарантія становить 24 місяці.');
        $response->assertJsonPath('sources.0.document_id', $document->id);
        $response->assertJsonPath('sources.0.document_name', 'warranty.pdf');
        $this->assertNotEmpty($response->json('session_id'));
        $this->assertNotEmpty($response->json('query_log_id'));

        $this->assertDatabaseHas('query_logs', [
            'id' => $response->json('query_log_id'),
            'question' => 'Яка гарантія на виріб X?',
        ]);
    }
}
