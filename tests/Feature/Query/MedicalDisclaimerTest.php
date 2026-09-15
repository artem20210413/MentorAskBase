<?php

namespace Tests\Feature\Query;

use App\Models\Document;
use App\Models\DocumentChunk;
use App\Services\Rag\KnowledgeBaseSearchTool;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Responses\Embeddings\CreateResponse as EmbeddingsCreateResponse;
use Tests\Support\FakesAgentResponses;
use Tests\TestCase;

class MedicalDisclaimerTest extends TestCase
{
    use FakesAgentResponses;

    public function test_serious_personal_medical_question_includes_ai_disclaimer(): void
    {
        $this->authenticateApiClient();

        OpenAI::fake([
            $this->fakeFinalAnswer(
                'Я лише ШІ-асистент, а не лікар — для остаточного рішення про операцію обов\'язково проконсультуйтеся з вашим хірургом. '
                .'У загальному вигляді показання залежать від стану здоров\'я та анатомічних особливостей.'
            ),
        ]);

        $response = $this->postJson('/api/v1/queries', [
            'question' => 'Чи підходить мені операція з імплантами MENTOR при моєму стані здоров\'я?',
        ]);

        $response->assertOk();
        $answer = $response->json('answer');

        $this->assertStringContainsStringIgnoringCase('ШІ-асистент', $answer);
        $this->assertStringContainsStringIgnoringCase('лікар', $answer);
    }

    public function test_simple_factual_question_has_no_intrusive_disclaimer(): void
    {
        $this->authenticateApiClient();

        $dimensions = config('rag.openai.embedding_dimensions', 1536);
        $document = Document::factory()->create(['status' => 'processed']);
        DocumentChunk::factory()->for($document)->create([
            'embedding' => array_fill(0, $dimensions, 0.1),
        ]);

        OpenAI::fake([
            $this->fakeFunctionToolCall('call_1', KnowledgeBaseSearchTool::NAME, ['query' => 'З чого виготовлені імпланти MENTOR MemoryGel?']),
            EmbeddingsCreateResponse::fake(['data' => [['embedding' => array_fill(0, $dimensions, 0.1)]]]),
            $this->fakeFinalAnswer('Імпланти MENTOR MemoryGel заповнені когезивним силіконовим гелем.'),
        ]);

        $response = $this->postJson('/api/v1/queries', ['question' => 'З чого виготовлені імпланти MENTOR MemoryGel?']);

        $response->assertOk();
        $answer = $response->json('answer');

        $this->assertStringNotContainsStringIgnoringCase('зверніться до лікаря', $answer);
        $this->assertStringNotContainsStringIgnoringCase('ШІ-асистент', $answer);
    }
}
