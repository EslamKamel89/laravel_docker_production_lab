<?php

namespace Tests\Feature;

use Tests\TestCase;

class ApiContractTest extends TestCase {
    public function test_health_endpoint(): void {
        $this->getJson('/api/health')
            ->assertOk()
            ->assertExactJson(['status' => 'ok']);
    }

    public function test_documents_start_empty(): void {
        $this->getJson('/api/documents')
            ->assertOk()
            ->assertExactJson(['data' => []]);
    }

    public function test_document_upload_is_not_yet_implemented(): void {
        $this->postJson('/api/documents')
            ->assertStatus(501);
    }

    public function test_missing_document_returns_404(): void {
        $this->getJson('/api/documents/42')
            ->assertNotFound();
    }

    public function test_search_is_not_yet_implemented(): void {
        $this->postJson('/api/search', [
            'query' => 'Docker networking',
        ])->assertStatus(501);
    }
}
