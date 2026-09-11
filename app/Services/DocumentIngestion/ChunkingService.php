<?php

namespace App\Services\DocumentIngestion;

class ChunkingService
{
    private readonly int $maxChunkLength;

    public function __construct(?int $maxChunkLength = null)
    {
        $this->maxChunkLength = $maxChunkLength ?? (int) config('rag.chunking.max_length', 1500);
    }

    /**
     * FR-004: розбиває текст сторінки на фрагменти прийнятного розміру для ембедингу.
     *
     * @return array<int, string>
     */
    public function chunk(string $text): array
    {
        $text = trim($text);

        if ($text === '') {
            return [];
        }

        $paragraphs = preg_split('/\n\s*\n/', $text) ?: [$text];

        $chunks = [];
        $current = '';

        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);

            if ($paragraph === '') {
                continue;
            }

            if ($current !== '' && mb_strlen($current.' '.$paragraph) > $this->maxChunkLength) {
                $chunks[] = trim($current);
                $current = '';
            }

            if (mb_strlen($paragraph) > $this->maxChunkLength) {
                foreach (mb_str_split($paragraph, $this->maxChunkLength) as $piece) {
                    $chunks[] = trim($piece);
                }

                continue;
            }

            $current = $current === '' ? $paragraph : $current.' '.$paragraph;
        }

        if ($current !== '') {
            $chunks[] = trim($current);
        }

        return $chunks;
    }
}
