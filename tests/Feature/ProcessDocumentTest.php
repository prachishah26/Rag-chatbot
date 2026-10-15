<?php

namespace Tests\Feature;

use App\Jobs\ProcessDocument;
use App\Models\Document;
use App\Services\Rag\Indexing\Chunk;
use App\Services\Rag\Indexing\DocumentChunker;
use App\Services\Rag\QdrantService;
use App\Services\Rag\Retrieval\Bm25Encoder;
use App\Services\Rag\Retrieval\Embedder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class ProcessDocumentTest extends TestCase
{
    use RefreshDatabase;

    private Document $document;
    private MockInterface $chunker;
    private MockInterface $embedder;
    private MockInterface $qdrant;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::disk('local')->put('documents/gep.pdf', 'pdf');
        config(['rag.qdrant.vector_size' => 8]);

        $this->document = Document::create([
            'original_name' => 'GEP.pdf',
            'path' => 'documents/gep.pdf',
            'mime_type' => 'application/pdf',
            'size' => 3,
            'status' => 'pending',
        ]);

        $this->chunker = Mockery::mock(DocumentChunker::class);
        $this->chunker->shouldReceive('chunk')
            ->with(Storage::disk('local')->path('documents/gep.pdf'), 'application/pdf', 'pdf')
            ->andReturn($this->chunks());
        $this->embedder = Mockery::mock(Embedder::class);
        $this->qdrant = Mockery::mock(QdrantService::class);
    }

    /** @return array<int, Chunk> */
    private function chunks(): array
    {
        return [
            new Chunk(0, 0, 'text', 'Growth in SAR is expected to soften to 6.3 percent.', 'Parent section about SAR growth.', 41,
                'Chapter 2 > South Asia', 'Chapter 2', 'South Asia'),
            new Chunk(1, 0, 'table', 'Table 2.5.1 India 6.5 6.3', 'Parent section about SAR growth.', 42,
                '', null, null),
        ];
    }

    private function handle(): void
    {
        (new ProcessDocument($this->document->id))->handle($this->chunker, $this->embedder, new Bm25Encoder(), $this->qdrant);
    }

    public function test_chunks_are_embedded_and_upserted_with_dense_and_bm25_vectors(): void
    {
        $vectors = [array_fill(0, 8, 0.1), array_fill(0, 8, 0.2)];
        $this->embedder->shouldReceive('embedDocuments')->once()
            ->with(["Chapter 2 > South Asia\nGrowth in SAR is expected to soften to 6.3 percent.", 'Table 2.5.1 India 6.5 6.3'])
            ->andReturn($vectors);

        $upserted = null;
        $this->qdrant->shouldReceive('deleteByDocument')->once()->with($this->document->id)->ordered();
        $this->qdrant->shouldReceive('upsert')->once()->with(Mockery::on(function (array $points) use (&$upserted) {
            $upserted = $points;

            return true;
        }))->ordered();

        $this->handle();

        $this->assertCount(2, $upserted);
        [$first, $second] = $upserted;

        $this->assertSame($this->document->id * 1000000 + 1, $first['id']);
        $this->assertSame($this->document->id * 1000000 + 2, $second['id']);
        $this->assertSame(['dense', 'bm25'], array_keys($first['vector']));
        $this->assertSame($vectors[0], $first['vector']['dense']);
        $this->assertSame((new Bm25Encoder())->encodeDocument("Chapter 2 > South Asia\nGrowth in SAR is expected to soften to 6.3 percent."), $first['vector']['bm25']);
        $this->assertContains(crc32('south'), $first['vector']['bm25']['indices'], 'BM25 includes the heading path');

        $this->assertSame([
            'document_id' => $this->document->id,
            'document_name' => 'GEP.pdf',
            'chunk_index' => 0,
            'parent_index' => 0,
            'type' => 'text',
            'page' => 41,
            'heading_path' => 'Chapter 2 > South Asia',
            'chapter' => 'Chapter 2',
            'region' => 'South Asia',
            'text' => 'Growth in SAR is expected to soften to 6.3 percent.',
            'parent_text' => 'Parent section about SAR growth.',
        ], $first['payload']);
        $this->assertNull($second['payload']['region']);
        $this->assertSame('table', $second['payload']['type']);

        $this->document->refresh();
        $this->assertSame('completed', $this->document->status);
        $this->assertSame(2, $this->document->chunk_count);
        $this->assertNull($this->document->error_message);
        $this->assertNotNull($this->document->processed_at);
    }

    public function test_wrong_vector_size_fails_the_document_and_rethrows(): void
    {
        $this->embedder->shouldReceive('embedDocuments')->andReturn([array_fill(0, 768, 0.1), array_fill(0, 768, 0.1)]);
        $this->qdrant->shouldReceive('deleteByDocument')->once();
        $this->qdrant->shouldNotReceive('upsert');

        try {
            $this->handle();
            $this->fail('Expected a dimension mismatch exception.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('dimension mismatch', $e->getMessage());
        }

        $this->document->refresh();
        $this->assertSame('failed', $this->document->status);
        $this->assertStringContainsString('Embedding dimension mismatch. Expected 8, got 768', $this->document->error_message);
    }

    public function test_document_without_text_fails(): void
    {
        $this->chunker = Mockery::mock(DocumentChunker::class);
        $this->chunker->shouldReceive('chunk')->andReturn([]);
        $this->qdrant->shouldNotReceive('deleteByDocument');

        try {
            $this->handle();
            $this->fail('Expected an exception.');
        } catch (RuntimeException $e) {
            $this->assertSame('No readable text was found in the document.', $e->getMessage());
        }

        $this->assertSame('failed', $this->document->refresh()->status);
    }
}
