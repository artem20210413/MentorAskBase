<?php

namespace Tests\Unit;

use App\Models\ConversationSession;
use App\Services\Conversation\ConversationSessionService;
use Tests\TestCase;

class ConversationSessionServiceTest extends TestCase
{
    public function test_expired_session_is_replaced_with_a_new_one(): void
    {
        config(['rag.session_timeout_minutes' => 60]);

        $old = ConversationSession::factory()->create([
            'last_activity_at' => now()->subMinutes(61),
        ]);

        $resolved = (new ConversationSessionService)->resolve($old->id);

        $this->assertNotSame($old->id, $resolved->id);
    }

    public function test_active_session_within_timeout_is_reused(): void
    {
        config(['rag.session_timeout_minutes' => 60]);

        $recent = ConversationSession::factory()->create([
            'last_activity_at' => now()->subMinutes(10),
        ]);

        $resolved = (new ConversationSessionService)->resolve($recent->id);

        $this->assertSame($recent->id, $resolved->id);
    }

    public function test_null_timeout_means_session_never_expires(): void
    {
        config(['rag.session_timeout_minutes' => null]);

        $veryOld = ConversationSession::factory()->create([
            'last_activity_at' => now()->subYears(1),
        ]);

        $resolved = (new ConversationSessionService)->resolve($veryOld->id);

        $this->assertSame($veryOld->id, $resolved->id);
    }
}
