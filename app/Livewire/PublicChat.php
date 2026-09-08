<?php

namespace App\Livewire;

use App\Models\QueryLog;
use App\Services\Conversation\ConversationSessionService;
use App\Services\Rag\AnswerGenerationService;
use App\Services\Rag\RagAnswerGenerationException;
use Livewire\Component;

class PublicChat extends Component
{
    public ?string $sessionId = null;

    public string $question = '';

    /** @var array<int, array{role: string, content: string, sources: array}> */
    public array $messages = [];

    public bool $isSending = false;

    public ?string $error = null;

    /**
     * FR-014: демонстраційний публічний канал поверх тієї самої RAG-логіки,
     * що й API (без Bearer-токена) — щоб показати роботу бота замовнику без
     * налаштування окремого клієнта.
     */
    public function ask(AnswerGenerationService $answerGenerationService, ConversationSessionService $sessionService): void
    {
        $question = trim($this->question);

        if ($question === '') {
            return;
        }

        $this->error = null;
        $this->isSending = true;
        $this->messages[] = ['role' => 'user', 'content' => $question, 'sources' => []];
        $this->question = '';

        $session = $sessionService->resolve($this->sessionId);
        $history = $sessionService->historyFor($session);

        try {
            $result = $answerGenerationService->answer($question, $history);
        } catch (RagAnswerGenerationException $e) {
            $this->error = 'Сервіс тимчасово недоступний, спробуйте ще раз.';
            $this->isSending = false;

            return;
        }

        QueryLog::create([
            'conversation_session_id' => $session->id,
            'question' => $question,
            'answer' => $result['answer'],
            'detected_language' => $result['language'],
            'answered_in_language' => $result['language'],
            'source_document_ids' => $result['sources']
                ->map(fn ($chunk) => ['document_id' => $chunk->document_id, 'page_number' => $chunk->page_number])
                ->unique(fn ($ref) => $ref['document_id'].':'.$ref['page_number'])
                ->values()
                ->all(),
            'best_match_score' => $result['match_score'],
            'llm_input_tokens' => $result['input_tokens'],
            'llm_output_tokens' => $result['output_tokens'],
        ]);

        $session->update(['last_activity_at' => now()]);
        $this->sessionId = $session->id;

        $this->messages[] = [
            'role' => 'assistant',
            'content' => $result['answer'],
            'sources' => $result['sources']->pluck('document.original_name')->unique()->values()->all(),
        ];

        $this->isSending = false;
    }

    public function render()
    {
        return view('livewire.public-chat');
    }
}
