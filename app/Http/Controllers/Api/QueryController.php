<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SubmitQueryRequest;
use App\Models\Document;
use App\Models\DocumentChunk;
use App\Models\QueryLog;
use App\Services\Conversation\ConversationSessionService;
use App\Services\Rag\AnswerGenerationService;
use App\Services\Rag\RagAnswerGenerationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

class QueryController extends Controller
{
    public function __construct(
        private readonly AnswerGenerationService $answerGenerationService,
        private readonly ConversationSessionService $sessionService,
    ) {}

    public function index(): JsonResponse
    {
        $logs = QueryLog::query()->latest('created_at')->get();

        return response()->json([
            'data' => $logs->map(fn (QueryLog $log) => $this->transform($log)),
        ]);
    }

    public function store(SubmitQueryRequest $request): JsonResponse
    {
        // FR-011a: невідома/прострочена сесія — автоматично нова, без помилки
        $session = $this->sessionService->resolve($request->input('session_id'));
        $history = $this->sessionService->historyFor($session);

        try {
            $result = $this->answerGenerationService->answer($request->input('question'), $history);
        } catch (RagAnswerGenerationException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        $log = QueryLog::create([
            'conversation_session_id' => $session->id,
            'question' => $request->input('question'),
            'answer' => $result['answer'],
            'detected_language' => $result['language'],
            'answered_in_language' => $result['language'],
            'source_document_ids' => $this->sourceReferences($result['sources']),
            'best_match_score' => $result['match_score'],
            'llm_input_tokens' => $result['input_tokens'],
            'llm_output_tokens' => $result['output_tokens'],
        ]);

        $session->update(['last_activity_at' => now()]);

        return response()->json([
            'query_log_id' => $log->id,
            'session_id' => $session->id,
            'answer' => $log->answer,
            'answer_language' => $log->answered_in_language,
            'sources' => $this->sourcesPayload($log),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function transform(QueryLog $log): array
    {
        return [
            'query_log_id' => $log->id,
            'session_id' => $log->conversation_session_id,
            'question' => $log->question,
            'answer' => $log->answer,
            'answer_language' => $log->answered_in_language,
            'sources' => $this->sourcesPayload($log),
            'created_at' => $log->created_at?->toIso8601String(),
        ];
    }

    /**
     * Унікальні пари (документ, сторінка), використані для відповіді —
     * найрелевантніші (найближчі) фрагменти йдуть першими (FR-009d).
     *
     * @param  Collection<int, DocumentChunk>  $chunks
     * @return array<int, array{document_id: string, page_number: ?int}>
     */
    private function sourceReferences($chunks): array
    {
        return $chunks
            ->map(fn ($chunk) => ['document_id' => $chunk->document_id, 'page_number' => $chunk->page_number])
            ->unique(fn ($ref) => $ref['document_id'].':'.$ref['page_number'])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{document_id: string, document_name: string, page_number: ?int, url: string}>
     */
    private function sourcesPayload(QueryLog $log): array
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
                    // Фрагмент #page=N відкриває конкретну сторінку у
                    // більшості вбудованих PDF-переглядачів браузера.
                    'url' => $ref['page_number'] ? "{$url}#page={$ref['page_number']}" : $url,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }
}
