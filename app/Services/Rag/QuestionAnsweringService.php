<?php

namespace App\Services\Rag;

use App\Models\Document;
use App\Models\QueryLog;
use App\Services\Conversation\ConversationSessionService;
use Illuminate\Support\Facades\Storage;

/**
 * FR-014: єдина точка входу "питання → відповідь" для всіх каналів (API,
 * публічний чат, майбутній Telegram-бот тощо). Кожен канал викликає лише
 * {@see self::ask()} — сесія, історія, звернення до LLM і журналювання
 * гарантовано працюють однаково незалежно від того, звідки прийшло питання.
 */
class QuestionAnsweringService
{
    public function __construct(
        private readonly AnswerGenerationService $answerGenerationService,
        private readonly ConversationSessionService $sessionService,
    ) {}

    /**
     * @throws RagAnswerGenerationException якщо LLM недоступна після повторної спроби (FR-009c)
     */
    public function ask(string $question, ?string $sessionId = null): QueryLog
    {
        // FR-011a: невідома/прострочена сесія — автоматично нова, без помилки
        $session = $this->sessionService->resolve($sessionId);
        $history = $this->sessionService->historyFor($session);

        $result = $this->answerGenerationService->answer($question, $history);

        $log = QueryLog::create([
            'conversation_session_id' => $session->id,
            'question' => $question,
            'answer' => $result['answer'],
            'detected_language' => $result['language'],
            'answered_in_language' => $result['language'],
            'source_document_ids' => $this->sourceReferences($result['sources']),
            'best_match_score' => $result['match_score'],
            'llm_input_tokens' => $result['input_tokens'],
            'llm_output_tokens' => $result['output_tokens'],
        ]);

        // FR-013: структурований журнал кроків агента, прив'язаний до цього
        // запису журналу питання-відповіді.
        if ($result['tool_steps'] !== []) {
            $log->toolSteps()->createMany($result['tool_steps']);
        }

        $session->update(['last_activity_at' => now()]);

        return $log;
    }

    /**
     * Джерела відповіді — документи бази знань і/або результати
     * інтернет-пошуку (FR-003, FR-009d).
     *
     * @return array<int, array<string, mixed>>
     */
    public function sources(QueryLog $log): array
    {
        $references = $log->source_document_ids ?? [];

        $documentIds = collect($references)
            ->filter(fn (array $ref) => ($ref['type'] ?? 'document') === 'document')
            ->pluck('document_id')
            ->unique();

        $documents = Document::withTrashed()->find($documentIds)->keyBy('id');

        return collect($references)
            ->map(function (array $ref) use ($documents) {
                $type = $ref['type'] ?? 'document';

                if ($type === 'web') {
                    return [
                        'type' => 'web',
                        'url' => $ref['url'],
                        'title' => $ref['title'] ?? null,
                        'relevance' => $ref['relevance'] ?? null,
                    ];
                }

                $document = $documents->get($ref['document_id']);

                if (! $document) {
                    return null;
                }

                // Пряме посилання на файл через публічний диск (storage:link),
                // без потреби в Bearer-токені — усвідомлений компроміс, див.
                // config/rag.php:document_disk.
                $url = Storage::disk(config('rag.document_disk'))->url($document->storage_path);

                return [
                    'type' => 'document',
                    'document_id' => $document->id,
                    'document_name' => $document->original_name,
                    'page_number' => $ref['page_number'],
                    // ?? null — старі записи журналу (до додавання цього поля) його не мають
                    'relevance' => $ref['relevance'] ?? null,
                    // Фрагмент #page=N відкриває конкретну сторінку у
                    // більшості вбудованих PDF-переглядачів браузера.
                    'url' => $ref['page_number'] ? "{$url}#page={$ref['page_number']}" : $url,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Унікальні джерела (документ+сторінка або URL), використані для
     * відповіді, з відсотком релевантності — найрелевантніші йдуть першими.
     *
     * @param  array<int, array<string, mixed>>  $sources
     * @return array<int, array<string, mixed>>
     */
    private function sourceReferences(array $sources): array
    {
        return collect($sources)
            ->map(fn (array $s) => $s['type'] === 'web'
                ? ['type' => 'web', 'url' => $s['url'], 'title' => $s['title'] ?? null, 'relevance' => $s['relevance'] ?? null]
                : ['type' => 'document', 'document_id' => $s['document_id'], 'page_number' => $s['page_number'], 'relevance' => $s['relevance']])
            ->unique(fn (array $ref) => $ref['type'] === 'web' ? 'web:'.$ref['url'] : 'document:'.$ref['document_id'].':'.$ref['page_number'])
            ->values()
            ->all();
    }
}
