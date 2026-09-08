<?php

namespace App\Livewire;

use App\Services\Rag\QuestionAnsweringService;
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
     * Демонстраційний публічний канал поверх тієї самої RAG-логіки, що й API
     * (без Bearer-токена) — щоб показати роботу бота замовнику без
     * налаштування окремого клієнта. Використовує той самий
     * QuestionAnsweringService::ask(), що й QueryController (FR-014).
     */
    public function ask(QuestionAnsweringService $questionAnsweringService): void
    {
        $question = trim($this->question);

        if ($question === '') {
            return;
        }

        $this->error = null;
        $this->isSending = true;
        $this->messages[] = ['role' => 'user', 'content' => $question, 'sources' => []];
        $this->question = '';

        try {
            $log = $questionAnsweringService->ask($question, $this->sessionId);
        } catch (RagAnswerGenerationException $e) {
            $this->error = 'Сервіс тимчасово недоступний, спробуйте ще раз.';
            $this->isSending = false;

            return;
        }

        $this->sessionId = $log->conversation_session_id;

        $this->messages[] = [
            'role' => 'assistant',
            'content' => $log->answer,
            'sources' => collect($questionAnsweringService->sources($log))->pluck('document_name')->unique()->values()->all(),
        ];

        $this->isSending = false;
    }

    public function render()
    {
        return view('livewire.public-chat');
    }
}
