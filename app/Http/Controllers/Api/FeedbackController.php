<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SubmitFeedbackRequest;
use App\Models\AnswerFeedback;
use App\Models\QueryLog;
use Illuminate\Http\JsonResponse;

class FeedbackController extends Controller
{
    public function store(SubmitFeedbackRequest $request, string $queryLogId): JsonResponse
    {
        $log = QueryLog::find($queryLogId);

        if (! $log) {
            return response()->json(['message' => 'Запис журналу не знайдено.'], 404);
        }

        // FR-010c: повторна оцінка замінює попередню (не створює дублікат)
        $feedback = AnswerFeedback::updateOrCreate(
            ['query_log_id' => $log->id],
            ['rating' => $request->input('rating'), 'comment' => $request->input('comment')]
        );

        return response()->json([
            'query_log_id' => $log->id,
            'rating' => $feedback->rating,
            'comment' => $feedback->comment,
        ]);
    }
}
