<?php

namespace Database\Factories;

use App\Models\ConversationSession;
use App\Models\QueryLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QueryLog>
 */
class QueryLogFactory extends Factory
{
    protected $model = QueryLog::class;

    public function definition(): array
    {
        return [
            'conversation_session_id' => ConversationSession::factory(),
            'question' => $this->faker->sentence().'?',
            'answer' => $this->faker->paragraph(),
            'detected_language' => 'uk',
            'answered_in_language' => 'uk',
            'source_document_ids' => [],
            'created_at' => now(),
        ];
    }
}
