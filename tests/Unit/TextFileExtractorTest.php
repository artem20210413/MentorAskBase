<?php

namespace Tests\Unit;

use App\Services\DocumentIngestion\TextFileExtractor;
use RuntimeException;
use Tests\TestCase;

class TextFileExtractorTest extends TestCase
{
    public function test_extract_returns_utf8_content(): void
    {
        $text = (new TextFileExtractor)->extract(
            base_path('tests/Fixtures/documents/notes.txt')
        );

        $this->assertStringContainsString('тестовий текстовий файл', $text);
    }

    public function test_assert_readable_passes_for_utf8_file(): void
    {
        (new TextFileExtractor)->assertReadable(
            base_path('tests/Fixtures/documents/notes.txt')
        );

        $this->addToAssertionCount(1);
    }

    public function test_assert_readable_throws_for_non_utf8_file(): void
    {
        $this->expectException(RuntimeException::class);

        (new TextFileExtractor)->assertReadable(
            base_path('tests/Fixtures/documents/notes-cp1251.txt')
        );
    }
}
