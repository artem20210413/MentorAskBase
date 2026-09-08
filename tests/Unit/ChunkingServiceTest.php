<?php

namespace Tests\Unit;

use App\Services\DocumentIngestion\ChunkingService;
use PHPUnit\Framework\TestCase;

class ChunkingServiceTest extends TestCase
{
    public function test_empty_text_produces_no_chunks(): void
    {
        $this->assertSame([], (new ChunkingService)->chunk('   '));
    }

    public function test_short_text_becomes_single_chunk(): void
    {
        $chunks = (new ChunkingService)->chunk("Перший абзац.\n\nДругий абзац.");

        $this->assertSame(['Перший абзац. Другий абзац.'], $chunks);
    }

    public function test_long_paragraph_is_split_into_multiple_chunks(): void
    {
        $longParagraph = str_repeat('слово ', 400); // явно перевищує ліміт у 1500 символів

        $chunks = (new ChunkingService)->chunk($longParagraph);

        $this->assertGreaterThan(1, count($chunks));
        foreach ($chunks as $chunk) {
            $this->assertLessThanOrEqual(1500, mb_strlen($chunk));
        }
    }
}
