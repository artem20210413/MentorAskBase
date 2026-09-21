<?php

namespace App\Services\Rag;

use App\Services\OpenAiRetry;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Responses\Responses\Output\OutputFunctionToolCall;
use OpenAI\Responses\Responses\Output\OutputMessage;
use OpenAI\Responses\Responses\Output\OutputMessageContentOutputText;
use OpenAI\Responses\Responses\Output\OutputMessageContentOutputTextAnnotationsUrlCitation;
use OpenAI\Responses\Responses\Output\OutputWebSearchToolCall;
use Throwable;

class AgentToolRunner
{
    public function __construct(private readonly KnowledgeBaseSearchTool $knowledgeBaseSearchTool) {}

    /**
     * FR-001/FR-002/FR-004/FR-005/FR-006/FR-008/FR-013: обмежений цикл
     * виклику інструментів через OpenAI Responses API — модель самостійно
     * вирішує, чи викликати `search_knowledge_base` (клієнтський
     * function-tool) і/або вбудований `web_search` (оркеструється
     * сервером OpenAI в межах того самого виклику). Зупиняється після
     * `config('rag.agent.max_tool_steps')` кроків.
     *
     * @param  array<int, array{role: string, content: string}>  $input  початкова розмова (історія + поточне питання)
     * @return array{answer: string, sources: array<int, array<string, mixed>>, tool_steps: array<int, array{step_number: int, tool: string, input: string, output: ?string}>, input_tokens: ?int, output_tokens: ?int}
     */
    public function run(string $instructions, array $input): array
    {
        $maxSteps = (int) config('rag.agent.max_tool_steps');
        $tools = [$this->knowledgeBaseSearchTool->schema(), $this->webSearchToolDefinition()];

        $sources = [];
        $toolSteps = [];
        $stepNumber = 0;
        $previousResponseId = null;
        $nextInput = $input;
        $finalText = null;
        $inputTokens = null;
        $outputTokens = null;

        for ($round = 0; $round < $maxSteps; $round++) {
            try {
                $response = $this->call($instructions, $tools, $nextInput, $previousResponseId);
            } catch (Throwable $e) {
                // FR-008: технічна недоступність (наприклад, web_search) не
                // має провалювати всю відповідь, якщо вже є що показати.
                if ($round > 0) {
                    break;
                }

                throw $e;
            }

            $previousResponseId = $response->id;

            if ($response->usage !== null) {
                $inputTokens = ($inputTokens ?? 0) + $response->usage->inputTokens;
                $outputTokens = ($outputTokens ?? 0) + $response->usage->outputTokens;
            }

            $functionCalls = [];
            $lastWebSearchStepIndex = null;

            foreach ($response->output as $item) {
                if ($item instanceof OutputFunctionToolCall) {
                    $functionCalls[] = $item;

                    continue;
                }

                if ($item instanceof OutputWebSearchToolCall) {
                    $toolSteps[] = [
                        'step_number' => $stepNumber++,
                        'tool' => 'web_search',
                        'input' => $item->action?->query ?? '',
                        'output' => $this->summarizeWebSearchAction($item),
                    ];
                    $lastWebSearchStepIndex = count($toolSteps) - 1;

                    continue;
                }

                if ($item instanceof OutputMessage) {
                    [$text, $webSources] = $this->extractMessage($item);
                    $finalText = ($finalText ?? '').$text;
                    array_push($sources, ...$webSources);

                    // FR-013: action->sources від OpenAI для web_search_call
                    // завжди null (API не віддає перелік знайдених сторінок
                    // на цьому кроці) — реальні посилання приходять лише як
                    // url-цитати у фінальному повідомленні цього ж раунду,
                    // тож дописуємо їх заднім числом в output останнього
                    // web_search кроку цього раунду.
                    if ($webSources !== [] && isset($lastWebSearchStepIndex)) {
                        $toolSteps[$lastWebSearchStepIndex]['output'] = json_encode(
                            array_map(fn (array $s) => ['url' => $s['url'], 'title' => $s['title']], $webSources),
                            JSON_UNESCAPED_UNICODE
                        );
                    }
                }
            }

            if ($functionCalls === []) {
                break;
            }

            $nextInput = [];

            foreach ($functionCalls as $call) {
                $step = $this->executeFunctionCall($call, $stepNumber);
                $stepNumber++;
                $toolSteps[] = $step['tool_step'];
                array_push($sources, ...$step['sources']);
                $nextInput[] = [
                    'type' => 'function_call_output',
                    'call_id' => $call->callId,
                    'output' => $step['output_text'],
                ];
            }
        }

        if ($finalText === null) {
            $finalText = $this->forceFinalAnswer($instructions, $nextInput, $previousResponseId);
        }

        return [
            'answer' => trim($finalText),
            'sources' => $sources,
            'tool_steps' => $toolSteps,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function webSearchToolDefinition(): array
    {
        $tool = ['type' => 'web_search'];

        $allowedDomains = config('rag.web_search.allowed_domains');

        if ($allowedDomains !== []) {
            // FR-008a/b: обмежує вбудований пошук лише дозволеними доменами.
            $tool['filters'] = ['allowed_domains' => $allowedDomains];
        }

        return $tool;
    }

    /**
     * @param  array<int, array<string, mixed>>  $tools
     * @param  array<int, mixed>  $input
     */
    private function call(string $instructions, array $tools, array $input, ?string $previousResponseId): \OpenAI\Responses\Responses\CreateResponse
    {
        $params = [
            'model' => config('rag.openai.chat_model'),
            'instructions' => $instructions,
            'tools' => $tools,
            'input' => $input,
        ];

        if ($previousResponseId !== null) {
            $params['previous_response_id'] = $previousResponseId;
        }

        return OpenAiRetry::attempt(fn () => OpenAI::responses()->create($params));
    }

    /**
     * @return array{0: string, 1: array<int, array<string, mixed>>}
     */
    private function extractMessage(OutputMessage $message): array
    {
        $text = '';
        $webSources = [];

        foreach ($message->content as $part) {
            if (! $part instanceof OutputMessageContentOutputText) {
                continue;
            }

            $text .= $part->text;

            foreach ($part->annotations as $annotation) {
                if ($annotation instanceof OutputMessageContentOutputTextAnnotationsUrlCitation) {
                    $webSources[] = [
                        'type' => 'web',
                        'url' => $annotation->url,
                        'title' => $annotation->title,
                        'relevance' => null,
                    ];
                }
            }
        }

        return [$text, $webSources];
    }

    /**
     * @return array{tool_step: array{step_number: int, tool: string, input: string, output: ?string}, sources: array<int, array<string, mixed>>, output_text: string}
     */
    private function executeFunctionCall(OutputFunctionToolCall $call, int $stepNumber): array
    {
        $arguments = json_decode($call->arguments, true) ?? [];
        $query = (string) ($arguments['query'] ?? '');

        try {
            $results = $this->knowledgeBaseSearchTool->execute($query);
        } catch (Throwable $e) {
            return [
                'tool_step' => ['step_number' => $stepNumber, 'tool' => 'search_knowledge_base', 'input' => $query, 'output' => null],
                'sources' => [],
                'output_text' => 'The knowledge base search failed. Answer based on other available information.',
            ];
        }

        $sources = array_map(
            fn (array $r) => [
                'type' => 'document',
                'document_id' => $r['document_id'],
                'page_number' => $r['page_number'],
                'relevance' => $r['relevance'],
            ],
            $results
        );

        $outputText = $results === []
            ? 'No relevant results found in the knowledge base.'
            : json_encode(array_map(fn (array $r) => [
                'document' => $r['document_name'],
                'page_number' => $r['page_number'],
                'relevance_percent' => $r['relevance'],
                'content' => $r['content'],
            ], $results), JSON_UNESCAPED_UNICODE);

        return [
            'tool_step' => ['step_number' => $stepNumber, 'tool' => 'search_knowledge_base', 'input' => $query, 'output' => $outputText],
            'sources' => $sources,
            'output_text' => $outputText,
        ];
    }

    private function summarizeWebSearchAction(OutputWebSearchToolCall $call): ?string
    {
        $sources = $call->action?->sources;

        if ($sources === null) {
            return null;
        }

        return json_encode(array_map(fn ($s) => $s->toArray(), $sources), JSON_UNESCAPED_UNICODE);
    }

    /**
     * FR-006: якщо ліміт кроків вичерпано без фінального текстового
     * повідомлення від моделі, примусово запитуємо відповідь на основі
     * вже зібраної інформації, без подальших викликів інструментів.
     *
     * @param  array<int, mixed>  $input
     */
    private function forceFinalAnswer(string $instructions, array $input, ?string $previousResponseId): string
    {
        if ($input === []) {
            return '';
        }

        $params = [
            'model' => config('rag.openai.chat_model'),
            'instructions' => $instructions,
            'tool_choice' => 'none',
            'input' => $input,
        ];

        if ($previousResponseId !== null) {
            $params['previous_response_id'] = $previousResponseId;
        }

        try {
            $response = OpenAiRetry::attempt(fn () => OpenAI::responses()->create($params));
        } catch (Throwable) {
            return '';
        }

        $text = '';

        foreach ($response->output as $item) {
            if ($item instanceof OutputMessage) {
                [$itemText] = $this->extractMessage($item);
                $text .= $itemText;
            }
        }

        return $text;
    }
}
