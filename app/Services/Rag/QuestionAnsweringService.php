<?php

namespace App\Services\Rag;

use App\Models\Document;
use App\Models\DocumentChunk;
use App\Models\QueryLog;
use App\Services\Conversation\ConversationSessionService;
use Illuminate\Support\Collection;
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

        $session->update(['last_activity_at' => now()]);

        return $log;
    }

    /**
     * Джерела відповіді з назвою документа, прямим посиланням і відсотком
     * релевантності фрагмента, з якого взято інформацію (FR-009d).
     *
     * @return array<int, array{document_id: string, document_name: string, page_number: ?int, relevance: int, url: string}>
     */
    public function sources(QueryLog $log): array
    {
        $references = $log->source_document_ids ?? [];
        $documents = Document::withTrashed()->find(collect($references)->pluck('document_id')->unique())
            ->keyBy('id');

        return collect($references)
            ->map(function (array $ref) use ($documents) {
                $document = $documents->get($ref['document_id']);

                if (! $document) {
                    return null;
                }

                // Пряме посилання на файл через публічний диск (storage:link),
                // без потреби в Bearer-токені — усвідомлений компроміс, див.
                // config/rag.php:document_disk.
                $url = Storage::disk(config('rag.document_disk'))->url($document->storage_path);

                return [
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
     * Унікальні пари (документ, сторінка), використані для відповіді, з
     * відсотком релевантності — найрелевантніші (найближчі) фрагменти йдуть
     * першими. Якщо на одну сторінку припадає кілька фрагментів, лишається
     * той, що дав найвищу релевантність.
     *
     * @param  Collection<int, DocumentChunk>  $chunks
     * @return array<int, array{document_id: string, page_number: ?int, relevance: int}>
     */
    private function sourceReferences(Collection $chunks): array
    {
        return $chunks
            ->map(fn (DocumentChunk $chunk) => [
                'document_id' => $chunk->document_id,
                'page_number' => $chunk->page_number,
                'relevance' => (int) round(max(0, 1 - $chunk->neighbor_distance) * 100),
            ])
            ->unique(fn (array $ref) => $ref['document_id'].':'.$ref['page_number'])
            ->values()
            ->all();
    }
}
