<?php

namespace App\Services\DocumentIngestion;

class ChunkingService
{
    private const MAX_CHUNK_LENGTH = 1500;

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

            if ($current !== '' && mb_strlen($current.' '.$paragraph) > self::MAX_CHUNK_LENGTH) {
                $chunks[] = trim($current);
                $current = '';
            }

            if (mb_strlen($paragraph) > self::MAX_CHUNK_LENGTH) {
                foreach (mb_str_split($paragraph, self::MAX_CHUNK_LENGTH) as $piece) {
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
