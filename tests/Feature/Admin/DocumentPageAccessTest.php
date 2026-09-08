<?php

namespace Tests\Feature\Admin;

use Tests\TestCase;

class DocumentPageAccessTest extends TestCase
{
    public function test_documents_page_is_accessible_without_login(): void
    {
        // FR-019: без будь-якої автентифікації/входу в систему
        $response = $this->get('/admin/documents');

        $response->assertOk();
    }
}
