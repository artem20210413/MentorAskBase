<?php

namespace Tests\Unit;

use Tests\TestCase;

class RagConfigTest extends TestCase
{
    public function test_non_empty_env_produces_array_of_domains(): void
    {
        putenv('RAG_WEB_SEARCH_ALLOWED_DOMAINS=jnjmedtech.com, mentorwwllc.com');
        $this->refreshApplication();

        $this->assertSame(['jnjmedtech.com', 'mentorwwllc.com'], config('rag.web_search.allowed_domains'));

        putenv('RAG_WEB_SEARCH_ALLOWED_DOMAINS');
    }

    public function test_empty_env_produces_empty_array(): void
    {
        putenv('RAG_WEB_SEARCH_ALLOWED_DOMAINS=');
        $this->refreshApplication();

        $this->assertSame([], config('rag.web_search.allowed_domains'));
    }
}
