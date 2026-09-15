<?php

namespace Tests\Feature\Query;

use App\Models\Document;
use App\Models\DocumentChunk;
use App\Services\Rag\KnowledgeBaseSearchTool;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Responses\Embeddings\CreateResponse as EmbeddingsCreateResponse;
use Tests\Support\FakesAgentResponses;
use Tests\TestCase;

class OutOfScopeQuestionTest extends TestCase
{
    use FakesAgentResponses;

    public function test_question_unrelated_to_breast_implants_or_mentor_is_declined_without_tool_calls(): void
    {
        $this->authenticateApiClient();

        OpenAI::fake([
            $this->fakeFinalAnswer('Вибачте, це поза межами моєї спеціалізації — грудні імпланти й компанія Mentor.'),
        ]);

        $response = $this->postJson('/api/v1/queries', ['question' => 'Який рецепт борщу?']);

        $response->assertOk();
        $response->assertJsonPath('sources', []);
        $this->assertNotEmpty($response->json('answer'));
    }

    public function test_adjacent_topic_general_plastic_surgery_is_treated_as_out_of_scope(): void
    {
        $this->authenticateApiClient();

        OpenAI::fake([
            $this->fakeFinalAnswer('Це поза межами моєї вузької спеціалізації на імплантах Mentor.'),
        ]);

        $response = $this->postJson('/api/v1/queries', ['question' => 'Скільки коштує пластична хірургія носа?']);

        $response->assertOk();
        $response->assertJsonPath('sources', []);
    }

    public function test_question_about_mentor_implants_still_works_normally(): void
    {
        $this->authenticateApiClient();

        $dimensions = config('rag.openai.embedding_dimensions', 1536);
        $document = Document::factory()->create(['status' => 'processed', 'original_name' => 'mentor-faq.pdf']);
        DocumentChunk::factory()->for($document)->create([
            'embedding' => array_fill(0, $dimensions, 0.1),
        ]);

        OpenAI::fake([
            $this->fakeFunctionToolCall('call_1', KnowledgeBaseSearchTool::NAME, ['query' => 'З чого зроблені імпланти MENTOR MemoryGel?']),
            EmbeddingsCreateResponse::fake(['data' => [['embedding' => array_fill(0, $dimensions, 0.1)]]]),
            $this->fakeFinalAnswer('Імпланти MENTOR MemoryGel заповнені силіконовим гелем.'),
        ]);

        $response = $this->postJson('/api/v1/queries', ['question' => 'З чого зроблені імпланти MENTOR MemoryGel?']);

        $response->assertOk();
        $response->assertJsonCount(1, 'sources');
    }
}
