<?php

namespace Database\Factories;

use App\Models\Document;
use App\Models\DocumentChunk;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentChunk>
 */
class DocumentChunkFactory extends Factory
{
    protected $model = DocumentChunk::class;

    public function definition(): array
    {
        return [
            'document_id' => Document::factory(),
            'position' => $this->faker->numberBetween(0, 10),
            'page_number' => $this->faker->numberBetween(1, 20),
            'content' => $this->faker->paragraph(),
            'source' => 'text_layer',
            'embedding' => array_fill(0, config('rag.openai.embedding_dimensions', 1536), 0.0),
        ];
    }
}
