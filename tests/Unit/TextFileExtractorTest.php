<?php

namespace Tests\Unit;

use App\Services\DocumentIngestion\TextFileExtractor;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * FR-004b: .txt у поширених кодуваннях (UTF-8, UTF-8 з BOM, UTF-16 з BOM,
 * Windows-1251) автоматично перекодовується в UTF-8; двійкові файли
 * відхиляються з поясненням причини.
 */
class TextFileExtractorTest extends TestCase
{
    private const SAMPLE = "Привіт, світ! Це перевірка кодування: ґанок, їжак, Єва, ІІ.\nДругий рядок — тире й «лапки».";

    private string $path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = tempnam(sys_get_temp_dir(), 'txt');
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        parent::tearDown();
    }

    public function test_extract_returns_utf8_content(): void
    {
        $text = (new TextFileExtractor)->extract(base_path('tests/Fixtures/documents/notes.txt'));

        $this->assertStringContainsString('тестовий текстовий файл', $text);
    }

    public function test_windows_1251_fixture_is_recoded_to_utf8(): void
    {
        $text = (new TextFileExtractor)->extract(base_path('tests/Fixtures/documents/notes-cp1251.txt'));

        $this->assertStringContainsString('Це текст у кодуванні Windows-1251', $text);
    }

    public static function encodings(): array
    {
        return [
            'UTF-8' => [fn (string $s) => $s],
            'UTF-8 з BOM' => [fn (string $s) => "\xEF\xBB\xBF".$s],
            'UTF-16LE з BOM' => [fn (string $s) => "\xFF\xFE".mb_convert_encoding($s, 'UTF-16LE', 'UTF-8')],
            'UTF-16BE з BOM' => [fn (string $s) => "\xFE\xFF".mb_convert_encoding($s, 'UTF-16BE', 'UTF-8')],
            'Windows-1251' => [fn (string $s) => mb_convert_encoding($s, 'Windows-1251', 'UTF-8')],
        ];
    }

    #[DataProvider('encodings')]
    public function test_text_in_supported_encoding_is_decoded_exactly(callable $encode): void
    {
        $sample = self::SAMPLE;
        file_put_contents($this->path, $encode($sample));

        $extractor = new TextFileExtractor;
        $extractor->assertReadable($this->path);

        $this->assertSame($sample, $extractor->extract($this->path));
    }

    public function test_to_utf8_strips_bom_and_keeps_text(): void
    {
        $this->assertSame('abc', (new TextFileExtractor)->toUtf8("\xEF\xBB\xBFabc"));
    }

    public static function binaryContents(): array
    {
        return [
            'PNG' => ["\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x00\x01"],
            'нульові байти' => ["text\x00\x00\x00more\x00binary"],
            'керівні символи' => [str_repeat("\x01\x02\x03\x04\x05\x06\x07\x08", 20)],
        ];
    }

    #[DataProvider('binaryContents')]
    public function test_binary_content_is_rejected(string $content): void
    {
        file_put_contents($this->path, $content);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(__('documents.unsupported_encoding'));

        (new TextFileExtractor)->assertReadable($this->path);
    }
}
