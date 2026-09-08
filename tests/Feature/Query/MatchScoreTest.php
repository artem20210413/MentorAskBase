<?php

namespace Tests\Feature\Query;

use App\Models\Document;
use App\Models\DocumentChunk;
use App\Models\QueryLog;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Responses\Chat\CreateResponse;
use OpenAI\Responses\Embeddings\CreateResponse as EmbeddingsCreateResponse;
use Tests\TestCase;

class MatchScoreTest extends TestCase
{
    public function test_best_match_score_is_stored_for_identical_embedding(): void
    {
        $this->authenticateApiClient();

        $dimensions = config('rag.openai.embedding_dimensions', 1536);
        $document = Document::factory()->create(['status' => 'processed']);
        DocumentChunk::factory()->for($document)->create([
            'embedding' => array_fill(0, $dimensions, 0.1),
        ]);

        OpenAI::fake([
            EmbeddingsCreateResponse::fake(['data' => [['embedding' => array_fill(0, $dimensions, 0.1)]]]),
            CreateResponse::fake(['choices' => [['message' => ['role' => 'assistant', 'content' => 'Відповідь.']]]]),
        ]);

        $response = $this->postJson('/api/v1/queries', ['question' => 'Питання?']);
        $response->assertOk();

        $log = QueryLog::first();

        // Ідентичні вектори питання й чанка → косинусна відстань 0 → 100%
        $this->assertSame(100, $log->best_match_score);
    }

    public function test_match_score_is_null_when_no_relevant_chunks_found(): void
    {
        $this->authenticateApiClient();

        $dimensions = config('rag.openai.embedding_dimensions', 1536);

        OpenAI::fake([
            EmbeddingsCreateResponse::fake(['data' => [['embedding' => array_fill(0, $dimensions, 0.1)]]]),
        ]);

        $response = $this->postJson('/api/v1/queries', ['question' => 'Питання без документів?']);
        $response->assertOk();

        $log = QueryLog::first();

        $this->assertNull($log->best_match_score);
    }
}
