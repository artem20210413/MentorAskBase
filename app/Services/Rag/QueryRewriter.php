<?php

namespace App\Services\Rag;

use OpenAI\Laravel\Facades\OpenAI;
use Throwable;

class QueryRewriter
{
    /**
     * Переформульовує останнє питання користувача в самодостатнє, чітке
     * питання для пошуку по базі знань. Історія передається як справжні
     * повідомлення діалогу (а не переказ текстом) — так само, як у фінальному
     * зверненні до LLM, — щоб модель "тримала контекст у голові" природно й
     * сама вирішувала, чи треба щось доповнити (наприклад, "а другий?" →
     * "яка гарантія на виріб Y?"). Якщо це перше питання сесії (історія
     * порожня) — переформулювання не потрібне, questions завжди самодостатні.
     *
     * @param  array<int, array{role: string, content: string}>  $history
     */
    public function rewriteForRetrieval(string $question, array $history): string
    {
        if (empty($history)) {
            return $question;
        }

        $messages = [
            [
                'role' => 'system',
                'content' => 'You help build a clear search query. The user\'s latest message below may rely on '.
                    'context from the earlier conversation (pronouns, shorthand references like "and the second '.
                    'one?"). Rewrite it into a self-contained question that makes sense without the history, using '.
                    'concrete terms from the earlier messages. If the question is already self-contained, return it '.
                    'unchanged. Output ONLY the final question, no explanations or quotes.',
            ],
            ...$history,
            ['role' => 'user', 'content' => $question],
        ];

        try {
            $response = OpenAI::chat()->create([
                'model' => config('rag.openai.chat_model'),
                'messages' => $messages,
            ]);

            $rewritten = trim($response->choices[0]->message->content ?? '');

            return $rewritten !== '' ? $rewritten : $question;
        } catch (Throwable) {
            // Збій переформулювання не має блокувати відповідь — шукаємо за оригінальним питанням.
            return $question;
        }
    }
}
