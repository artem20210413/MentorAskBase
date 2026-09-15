<?php

namespace Tests\Support;

use OpenAI\Responses\Responses\CreateResponse;
use OpenAI\Testing\Enums\OverrideStrategy;

trait FakesAgentResponses
{
    /**
     * Response, де модель одразу дає фінальну текстову відповідь без
     * виклику жодного інструмента.
     *
     * @param  array<int, array{url: string, title: string}>  $urlCitations
     */
    protected function fakeFinalAnswer(string $text, array $urlCitations = []): CreateResponse
    {
        return CreateResponse::fake([
            'output' => [
                [
                    'type' => 'message',
                    'id' => 'msg_'.uniqid(),
                    'status' => 'completed',
                    'role' => 'assistant',
                    'content' => [
                        [
                            'type' => 'output_text',
                            'text' => $text,
                            'annotations' => array_map(fn (array $c) => [
                                'type' => 'url_citation',
                                'start_index' => 0,
                                'end_index' => 1,
                                'url' => $c['url'],
                                'title' => $c['title'],
                            ], $urlCitations),
                        ],
                    ],
                ],
            ],
        ], strategy: OverrideStrategy::Replace);
    }

    /**
     * Response, де модель викликає function-tool (напр. search_knowledge_base).
     *
     * @param  array<string, mixed>  $arguments
     */
    protected function fakeFunctionToolCall(string $callId, string $toolName, array $arguments): CreateResponse
    {
        return CreateResponse::fake([
            'output' => [
                [
                    'type' => 'function_call',
                    'id' => 'fc_'.uniqid(),
                    'call_id' => $callId,
                    'name' => $toolName,
                    'arguments' => json_encode($arguments),
                    'status' => 'completed',
                ],
            ],
        ], strategy: OverrideStrategy::Replace);
    }

    /**
     * Response, де модель викликає вбудований web_search (повністю
     * оркеструється сервером — включно з фінальним текстом в одній відповіді).
     */
    protected function fakeWebSearchAnswer(string $text, string $query, array $urlCitations = []): CreateResponse
    {
        return CreateResponse::fake([
            'output' => [
                [
                    'type' => 'web_search_call',
                    'id' => 'ws_'.uniqid(),
                    'status' => 'completed',
                    'action' => ['type' => 'search', 'query' => $query, 'sources' => []],
                ],
                [
                    'type' => 'message',
                    'id' => 'msg_'.uniqid(),
                    'status' => 'completed',
                    'role' => 'assistant',
                    'content' => [
                        [
                            'type' => 'output_text',
                            'text' => $text,
                            'annotations' => array_map(fn (array $c) => [
                                'type' => 'url_citation',
                                'start_index' => 0,
                                'end_index' => 1,
                                'url' => $c['url'],
                                'title' => $c['title'],
                            ], $urlCitations),
                        ],
                    ],
                ],
            ],
        ], strategy: OverrideStrategy::Replace);
    }
}
