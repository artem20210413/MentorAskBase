<?php

namespace Database\Factories;

use App\Models\Document;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Document>
 */
class DocumentFactory extends Factory
{
    protected $model = Document::class;

    public function definition(): array
    {
        return [
            'original_name' => $this->faker->word().'.pdf',
            'content_hash' => hash('sha256', $this->faker->unique()->sentence()),
            'storage_path' => 'documents/'.$this->faker->uuid().'.pdf',
            'size_bytes' => $this->faker->numberBetween(1024, 1024 * 1024),
            'status' => 'processed',
        ];
    }

    public function processing(): static
    {
        return $this->state(['status' => 'processing']);
    }

    public function pending(): static
    {
        return $this->state(['status' => 'pending']);
    }

    public function failed(): static
    {
        return $this->state(['status' => 'failed', 'failure_reason' => 'Обробка перервана через збій']);
    }
}
