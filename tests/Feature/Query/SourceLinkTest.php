<?php

namespace Tests\Feature\Query;

use App\Models\Document;
use App\Models\DocumentChunk;
use Illuminate\Support\Facades\Storage;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Responses\Chat\CreateResponse;
use OpenAI\Responses\Embeddings\CreateResponse as EmbeddingsCreateResponse;
use Tests\TestCase;

class SourceLinkTest extends TestCase
{
    public function test_source_includes_page_number_and_direct_storage_link(): void
    {
        Storage::fake('public');
        $this->authenticateApiClient();

        $dimensions = config('rag.openai.embedding_dimensions', 1536);
        $document = Document::factory()->create([
            'status' => 'processed',
            'original_name' => 'brochure.pdf',
            'storage_path' => 'documents/brochure.pdf',
        ]);
        DocumentChunk::factory()->for($document)->create([
            'page_number' => 3,
            'embedding' => array_fill(0, $dimensions, 0.1),
        ]);

        OpenAI::fake([
            EmbeddingsCreateResponse::fake(['data' => [['embedding' => array_fill(0, $dimensions, 0.1)]]]),
            CreateResponse::fake(['choices' => [['message' => ['role' => 'assistant', 'content' => 'Відповідь.']]]]),
        ]);

        $response = $this->postJson('/api/v1/queries', ['question' => 'Питання?']);

        $response->assertOk();
        $response->assertJsonPath('sources.0.page_number', 3);

        // Пряме посилання на публічний диск (storage:link), без API-ендпоінту й без токена
        $expectedUrl = Storage::disk('public')->url('documents/brochure.pdf').'#page=3';
        $response->assertJsonPath('sources.0.url', $expectedUrl);
    }
}
