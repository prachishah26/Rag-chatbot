<?php

namespace Tests\Feature;

use App\Jobs\ProcessDocument;
use App\Models\Document;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class IngestDocumentCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $file;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Bus::fake([ProcessDocument::class]);
        $this->file = sys_get_temp_dir().'/handbook.txt';
        file_put_contents($this->file, 'Shared handbook text.');
    }

    protected function tearDown(): void
    {
        @unlink($this->file);

        parent::tearDown();
    }

    public function test_ingested_document_is_shared(): void
    {
        $this->artisan('rag:ingest', ['path' => $this->file])->assertSuccessful();

        $this->assertTrue(Document::sole()->isShared());
    }

    public function test_a_users_private_document_with_the_same_name_is_left_alone(): void
    {
        $private = Document::factory()->create(['original_name' => 'handbook.txt', 'path' => 'documents/private.txt']);

        $this->artisan('rag:ingest', ['path' => $this->file, '--replace' => true])->assertSuccessful();

        $this->assertSame('documents/private.txt', $private->fresh()->path);
        $this->assertSame(1, Document::whereNull('user_id')->where('original_name', 'handbook.txt')->count());
    }

    public function test_replace_reindexes_the_shared_document(): void
    {
        $shared = Document::factory()->shared()->create(['original_name' => 'handbook.txt']);

        $this->artisan('rag:ingest', ['path' => $this->file, '--replace' => true])->assertSuccessful();

        $this->assertSame(1, Document::count());
        $this->assertSame(Document::QUEUED, $shared->fresh()->status);
    }
}
