<?php

namespace Tests\Unit;

use App\Services\DocumentIngestion\WordTextExtractor;
use RuntimeException;
use Tests\TestCase;

class WordTextExtractorTest extends TestCase
{
    public function test_extract_returns_paragraph_text_in_reading_order(): void
    {
        $result = (new WordTextExtractor)->extract(
            base_path('tests/Fixtures/documents/document.docx')
        );

        $this->assertStringContainsString('тестовий документ Word', $result['text']);
        $this->assertSame([], $result['image_paths']);
    }

    public function test_extract_returns_temp_paths_for_embedded_images(): void
    {
        $result = (new WordTextExtractor)->extract(
            base_path('tests/Fixtures/documents/document-with-image.docx')
        );

        $this->assertCount(1, $result['image_paths']);
        $this->assertFileExists($result['image_paths'][0]);

        unlink($result['image_paths'][0]);
    }

    public function test_assert_readable_throws_for_fake_docx(): void
    {
        $this->expectException(RuntimeException::class);

        (new WordTextExtractor)->assertReadable(
            base_path('tests/Fixtures/documents/fake.docx')
        );
    }

    public function test_assert_readable_passes_for_valid_docx(): void
    {
        (new WordTextExtractor)->assertReadable(
            base_path('tests/Fixtures/documents/document.docx')
        );

        $this->addToAssertionCount(1);
    }
}
