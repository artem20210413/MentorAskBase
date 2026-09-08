<?php

namespace Database\Factories;

use App\Models\ConversationSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConversationSession>
 */
class ConversationSessionFactory extends Factory
{
    protected $model = ConversationSession::class;

    public function definition(): array
    {
        return [
            'last_activity_at' => now(),
        ];
    }
}
