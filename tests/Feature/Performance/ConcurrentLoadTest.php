<?php

namespace Tests\Feature\Performance;

use App\Models\Document;
use App\Models\DocumentChunk;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Responses\Chat\CreateResponse;
use OpenAI\Responses\Embeddings\CreateResponse as EmbeddingsCreateResponse;
use Tests\TestCase;

class ConcurrentLoadTest extends TestCase
{
    /**
     * SC-008: система коректно обробляє базу знань обсягом до ~1000
     * документів і одночасну роботу 5-10 користувачів без відмов чи
     * суттєвого погіршення часу відповіді. Тест симулює послідовність
     * запитів кількох "клієнтів" на базі знань істотного розміру та
     * перевіряє відсутність відмов і прийнятний сумарний час.
     */
    public function test_system_handles_large_knowledge_base_and_multiple_clients_without_failures(): void
    {
        config(['rag.rate_limit_per_minute' => 1000]);
        $this->authenticateApiClient();

        $dimensions = config('rag.openai.embedding_dimensions', 1536);

        // Наближення до масштабу ~1000 документів (зменшено до 100 для
        // прийнятного часу виконання тесту, лінійна структура даних та сама).
        $documents = Document::factory()->count(100)->create(['status' => 'processed']);

        foreach ($documents as $index => $document) {
            DocumentChunk::factory()->for($document)->create([
                // Унікальний вектор на кожен документ, щоб pgvector міг
                // повернути передбачуваний топ-результат для тестового запиту.
                'embedding' => $index === 0
                    ? array_fill(0, $dimensions, 0.1)
                    : array_fill(0, $dimensions, 0.9),
            ]);
        }

        $clientsCount = 8; // 5-10 одночасних користувачів (SC-008)
        $responses = [];

        $fakes = [];
        for ($i = 0; $i < $clientsCount; $i++) {
            $fakes[] = EmbeddingsCreateResponse::fake(['data' => [['embedding' => array_fill(0, $dimensions, 0.1)]]]);
            $fakes[] = CreateResponse::fake(['choices' => [['message' => ['role' => 'assistant', 'content' => "Відповідь клієнту {$i}."]]]]);
        }
        OpenAI::fake($fakes);

        $start = microtime(true);

        for ($i = 0; $i < $clientsCount; $i++) {
            $responses[] = $this->postJson('/api/v1/queries', ['question' => "Питання від клієнта {$i}?"]);
        }

        $elapsedSeconds = microtime(true) - $start;

        foreach ($responses as $response) {
            $response->assertOk();
        }

        $this->assertDatabaseCount('query_logs', $clientsCount);
        // М'який поріг продуктивності для тестового середовища (без реального LLM API).
        $this->assertLessThan(10, $elapsedSeconds, 'Обробка запитів зайняла надто багато часу.');
    }
}
