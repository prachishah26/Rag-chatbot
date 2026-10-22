<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocumentIndexPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_knowledge_base_shows_empty_state(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('documents.index'))
            ->assertOk()
            ->assertSee('No documents uploaded yet.');
    }

    public function test_documents_and_totals_are_listed(): void
    {
        $this->makeDocument('report.pdf', 'completed', 12);
        $this->makeDocument('notes.txt', 'processing', 0);
        $this->makeDocument('broken.pdf', 'failed', 0, 'Could not read PDF');

        $this->actingAs(User::factory()->create())
            ->get(route('documents.index'))
            ->assertOk()
            ->assertSee(['report.pdf', 'notes.txt', 'broken.pdf', 'Could not read PDF'])
            ->assertViewHas('stats', fn ($stats) => (int) $stats->total === 3
                && (int) $stats->chunks === 12
                && (int) $stats->pending === 1);
    }

    private function makeDocument(string $name, string $status, int $chunks, ?string $error = null): Document
    {
        return Document::create([
            'original_name' => $name,
            'path' => "documents/{$name}",
            'mime_type' => 'application/pdf',
            'size' => 2048,
            'status' => $status,
            'chunk_count' => $chunks,
            'error_message' => $error,
        ]);
    }
}
