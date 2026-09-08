<?php

namespace Tests\Unit;

use App\Services\Conversation\LanguageDetectionService;
use Tests\TestCase;

class LanguageDetectionServiceTest extends TestCase
{
    public function test_detects_ukrainian(): void
    {
        $language = (new LanguageDetectionService)->detect('Яка гарантія на цей виріб і скільки вона триває?');

        $this->assertSame('uk', $language);
    }

    public function test_detects_english(): void
    {
        $language = (new LanguageDetectionService)->detect('What is the warranty period for this product and how long does it last?');

        $this->assertSame('en', $language);
    }

    public function test_falls_back_to_default_for_unsupported_language(): void
    {
        $language = (new LanguageDetectionService)->detect('Quelle est la garantie de ce produit et combien de temps dure-t-elle vraiment?');

        $this->assertSame(config('rag.supported_languages')[0], $language);
    }
}
