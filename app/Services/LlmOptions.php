<?php

namespace App\Services;

/**
 * Збирає параметри генерації (temperature/top_p/ліміт токенів) з config('rag.llm')
 * у форму, яку очікує конкретний OpenAI API. Chat Completions і Responses
 * API називають ліміт токенів по-різному (max_tokens проти max_output_tokens),
 * тому для кожного є окремий метод.
 */
class LlmOptions
{
    /**
     * Параметри для Chat Completions API (OpenAI::chat()->create()).
     *
     * @return array<string, mixed>
     */
    public static function forChat(): array
    {
        return self::build('max_tokens');
    }

    /**
     * Параметри для Responses API (OpenAI::responses()->create()).
     *
     * @return array<string, mixed>
     */
    public static function forResponses(): array
    {
        return self::build('max_output_tokens');
    }

    /**
     * @return array<string, mixed>
     */
    private static function build(string $maxTokensKey): array
    {
        $options = [
            'temperature' => config('rag.llm.temperature'),
            'top_p' => config('rag.llm.top_p'),
        ];

        $maxTokens = config('rag.llm.max_tokens');

        if ($maxTokens !== null) {
            $options[$maxTokensKey] = $maxTokens;
        }

        return $options;
    }
}
