<?php

namespace App\Livewire;

use App\Services\Rag\QuestionAnsweringService;
use App\Services\Rag\RagAnswerGenerationException;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Locked;
use Livewire\Component;

class PublicChat extends Component
{
    /**
     * Locked: клієнт не може підставити довільний session_id і продовжити
     * чужу розмову (історія сесії потрапляє в контекст LLM).
     */
    #[Locked]
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

        // Та сама межа довжини, що й в API (SubmitQueryRequest) — довгий
        // текст означає дорогий запит до LLM.
        $this->validate(['question' => ['required', 'string', 'max:2000']]);

        // Питання йдуть через POST /livewire/update, тож throttle на GET /chat
        // їх не обмежує — ліміт по IP рахуємо тут, до звернення до LLM.
        $rateLimitKey = 'public-chat:'.request()->ip();
        if (RateLimiter::tooManyAttempts($rateLimitKey, (int) config('rag.rate_limit_per_minute'))) {
            $this->error = 'Забагато запитів. Спробуйте за хвилину.';

            return;
        }
        RateLimiter::hit($rateLimitKey, 60);

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

        $sources = collect($questionAnsweringService->sources($log))
            ->map(fn (array $source) => [
                'name' => $source['type'] === 'web' ? $source['title'] : $source['document_name'],
                'url' => $source['url'],
            ])
            ->unique('name')
            ->values()
            ->all();

        $this->messages[] = [
            'role' => 'assistant',
            'content' => $log->answer,
            'sources' => $sources,
        ];

        $this->isSending = false;
    }

    public function render()
    {
        return view('livewire.public-chat');
    }
}
