<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SubmitQueryRequest;
use App\Models\QueryLog;
use App\Services\Rag\QuestionAnsweringService;
use App\Services\Rag\RagAnswerGenerationException;
use Illuminate\Http\JsonResponse;

class QueryController extends Controller
{
    public function __construct(private readonly QuestionAnsweringService $questionAnsweringService) {}

    public function index(): JsonResponse
    {
        $logs = QueryLog::query()->latest('created_at')->get();

        return response()->json([
            'data' => $logs->map(fn (QueryLog $log) => $this->transform($log)),
        ]);
    }

    public function store(SubmitQueryRequest $request): JsonResponse
    {
        try {
            $log = $this->questionAnsweringService->ask($request->input('question'), $request->input('session_id'));
        } catch (RagAnswerGenerationException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        return response()->json([
            'query_log_id' => $log->id,
            'session_id' => $log->conversation_session_id,
            'answer' => $log->answer,
            'answer_language' => $log->answered_in_language,
            'sources' => $this->questionAnsweringService->sources($log),
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
            'sources' => $this->questionAnsweringService->sources($log),
            'created_at' => $log->created_at?->toIso8601String(),
        ];
    }
}
