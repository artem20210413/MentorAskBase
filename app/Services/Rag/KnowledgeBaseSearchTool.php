<?php

namespace App\Services\Rag;

use App\Models\DocumentChunk;

class KnowledgeBaseSearchTool
{
    public function __construct(
        private readonly EmbeddingService $embeddingService,
        private readonly VectorSearchService $vectorSearchService,
    ) {}

    public const NAME = 'search_knowledge_base';

    /**
     * FR-001/FR-005: схема function-tool для OpenAI Responses API — модель
     * вирішує сама, коли викликати цей інструмент, на основі опису.
     *
     * @return array<string, mixed>
     */
    public function schema(): array
    {
        return [
            'type' => 'function',
            'name' => self::NAME,
            'description' => __('bot.knowledge_base_tool_description'),
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'query' => [
                        'type' => 'string',
                        'description' => 'Self-contained search query in the language of the knowledge base content.',
                    ],
                ],
                'required' => ['query'],
                'additionalProperties' => false,
            ],
            'strict' => true,
        ];
    }

    /**
     * Виконує пошук по базі знань і повертає нормалізовані фрагменти —
     * включно з текстовим вмістом (для відповіді LLM) і метаданими джерела
     * (document_id/page_number/relevance, для подальшого цитування).
     *
     * @return array<int, array{type: string, document_id: string, document_name: string, page_number: ?int, relevance: int, content: string}>
     */
    public function execute(string $query): array
    {
        $embedding = $this->embeddingService->embed($query);

        return $this->vectorSearchService->search($embedding)
            ->map(fn (DocumentChunk $chunk) => [
                'type' => 'document',
                'document_id' => $chunk->document_id,
                'document_name' => $chunk->document->original_name,
                'page_number' => $chunk->page_number,
                'relevance' => VectorSearchService::relevancePercent($chunk->neighbor_distance),
                'content' => $chunk->content,
            ])
            ->all();
    }
}
