<?php

namespace Tests\Feature\Query;

use App\Models\Document;
use App\Models\DocumentChunk;
use App\Services\Rag\KnowledgeBaseSearchTool;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Responses\Embeddings\CreateResponse as EmbeddingsCreateResponse;
use Tests\Support\FakesAgentResponses;
use Tests\TestCase;

class LanguageMatchTest extends TestCase
{
    use FakesAgentResponses;

    public function test_answer_language_matches_question_language(): void
    {
        $this->authenticateApiClient();

        $dimensions = config('rag.openai.embedding_dimensions', 1536);
        $document = Document::factory()->create(['status' => 'processed']);
        DocumentChunk::factory()->for($document)->create([
            'embedding' => array_fill(0, $dimensions, 0.1),
        ]);

        OpenAI::fake([
            $this->fakeFunctionToolCall('call_1', KnowledgeBaseSearchTool::NAME, ['query' => 'What is the warranty period for product X?']),
            EmbeddingsCreateResponse::fake(['data' => [['embedding' => array_fill(0, $dimensions, 0.1)]]]),
            $this->fakeFinalAnswer('Answer in English.'),
        ]);

        $response = $this->postJson('/api/v1/queries', ['question' => 'What is the warranty period for product X?']);

        $response->assertOk();
        $response->assertJsonPath('answer_language', 'en');
    }

    public function test_unsupported_language_falls_back_to_default(): void
    {
        $this->authenticateApiClient();

        $dimensions = config('rag.openai.embedding_dimensions', 1536);
        $document = Document::factory()->create(['status' => 'processed']);
        DocumentChunk::factory()->for($document)->create([
            'embedding' => array_fill(0, $dimensions, 0.1),
        ]);

        $question = "Quelle est la garantie du produit X? C'est une question en français avec suffisamment de texte pour la détection.";

        OpenAI::fake([
            $this->fakeFunctionToolCall('call_1', KnowledgeBaseSearchTool::NAME, ['query' => $question]),
            EmbeddingsCreateResponse::fake(['data' => [['embedding' => array_fill(0, $dimensions, 0.1)]]]),
            $this->fakeFinalAnswer('Відповідь мовою за замовчуванням.'),
        ]);

        // Питання французькою — не входить до RAG_SUPPORTED_LANGUAGES=uk,en
        $response = $this->postJson('/api/v1/queries', ['question' => $question]);

        $response->assertOk();
        $response->assertJsonPath('answer_language', 'uk');
    }
}
