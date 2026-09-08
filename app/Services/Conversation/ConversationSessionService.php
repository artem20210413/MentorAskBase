<?php

namespace App\Services\Conversation;

use App\Models\ConversationSession;
use Carbon\Carbon;

class ConversationSessionService
{
    /**
     * FR-010a/FR-011a/FR-012/FR-012a: повертає існуючу активну сесію за
     * ідентифікатором, або створює нову, якщо ідентифікатор відсутній,
     * невідомий чи сесія прострочена через тайм-аут неактивності.
     */
    public function resolve(?string $sessionId): ConversationSession
    {
        if ($sessionId) {
            $session = ConversationSession::find($sessionId);

            if ($session && ! $this->isExpired($session)) {
                return $session;
            }
        }

        return ConversationSession::create(['last_activity_at' => now()]);
    }

    private function isExpired(ConversationSession $session): bool
    {
        $timeoutMinutes = config('rag.session_timeout_minutes');

        if ($timeoutMinutes === null) {
            return false;
        }

        return $session->last_activity_at->lt(Carbon::now()->subMinutes($timeoutMinutes));
    }

    /**
     * FR-011: попередні питання й відповіді сесії як контекст для LLM.
     *
     * @return array<int, array{role: string, content: string}>
     */
    public function historyFor(ConversationSession $session): array
    {
        $history = [];

        foreach ($session->queryLogs()->oldest('created_at')->get() as $log) {
            $history[] = ['role' => 'user', 'content' => $log->question];
            $history[] = ['role' => 'assistant', 'content' => $log->answer];
        }

        return $history;
    }
}
