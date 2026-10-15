<?php

namespace Tests\Unit\Indexing;

use App\Services\Rag\Indexing\Chunk;
use App\Services\Rag\Indexing\DocumentChunker;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class DocumentChunkerTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'rag.chunking.parent_size' => 3600,
            'rag.chunking.child_size' => 1100,
            'rag.chunking.overlap_sentences' => 1,
            'rag.vision.pages' => 'auto',
        ]);
        Http::fake();
        Process::fake();
        $this->path = tempnam(sys_get_temp_dir(), 'chunker').'.txt';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);

        parent::tearDown();
    }

    public function test_a_text_file_with_two_paragraphs_is_chunked(): void
    {
        file_put_contents($this->path, "Te economy grew last year. Investment recovered.\n\nInflation fell in most regions. Prices are stable.\n");

        $chunks = app(DocumentChunker::class)->chunk($this->path, 'text/plain', 'txt');

        $this->assertNotEmpty($chunks);
        $this->assertContainsOnlyInstancesOf(Chunk::class, $chunks);
        $this->assertSame(
            'The economy grew last year. Investment recovered. Inflation fell in most regions. Prices are stable.',
            $chunks[0]->parentText
        );
        $this->assertSame(1, $chunks[0]->page);
        $this->assertSame('', $chunks[0]->headingPath);
        $this->assertNull($chunks[0]->chapter);
        // No PDF tools or vision API calls for a text file.
        Process::assertNothingRan();
        Http::assertNothingSent();
    }
}
