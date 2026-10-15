<?php

namespace App\Jobs;

use App\Models\Document;
use App\Services\Rag\Indexing\Chunk;
use App\Services\Rag\Indexing\DocumentChunker;
use App\Services\Rag\QdrantService;
use App\Services\Rag\Retrieval\Bm25Encoder;
use App\Services\Rag\Retrieval\Embedder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class ProcessDocument implements ShouldQueue
{
    use Queueable;

    // Chunks embedded and written to Qdrant per request.
    private const BATCH = 32;

    public int $tries = 2;
    public int $timeout = 900;

    public function __construct(public int $documentId) {}

    public function handle(DocumentChunker $chunker, Embedder $embedder, Bm25Encoder $bm25, QdrantService $qdrant): void
    {
        $document = Document::findOrFail($this->documentId);
        $document->update(['status' => 'processing', 'error_message' => null]);

        try {
            $fullPath = Storage::disk('local')->path($document->path);
            $extension = strtolower(pathinfo($document->original_name, PATHINFO_EXTENSION));

            $chunks = $chunker->chunk($fullPath, $document->mime_type ?? '', $extension);

            if (!$chunks) {
                throw new RuntimeException('No readable text was found in the document.');
            }

            $qdrant->deleteByDocument($document->id);

            foreach (array_chunk($chunks, self::BATCH) as $batch) {
                $vectors = $embedder->embedDocuments(array_map(fn (Chunk $c) => $c->embeddingText(), $batch));
                $this->assertDimensions($vectors);

                $qdrant->upsert(array_map(
                    fn (Chunk $chunk, array $vector) => $this->point($document, $chunk, $vector, $bm25),
                    $batch,
                    $vectors
                ));
            }

            $document->update([
                'status' => 'completed',
                'chunk_count' => count($chunks),
                'processed_at' => now(),
                'error_message' => null,
            ]);
        } catch (Throwable $e) {
            $document->update([
                'status' => 'failed',
                // Service errors can echo whole response bodies; the documents page needs the gist.
                'error_message' => mb_strimwidth($e->getMessage(), 0, 500, '…'),
            ]);

            throw $e;
        }
    }

    private function point(Document $document, Chunk $chunk, array $vector, Bm25Encoder $bm25): array
    {
        return [
            'id' => $document->id * 1000000 + $chunk->index + 1,
            'vector' => [
                QdrantService::DENSE => $vector,
                // BM25 sees the heading path too, so "South Asia" matches a passage that only says "SAR".
                QdrantService::SPARSE => $bm25->encodeDocument($chunk->embeddingText()),
            ],
            'payload' => [
                'document_id' => $document->id,
                'document_name' => $document->original_name,
                'chunk_index' => $chunk->index,
                'parent_index' => $chunk->parentIndex,
                'type' => $chunk->type,
                'page' => $chunk->page,
                'heading_path' => $chunk->headingPath,
                'chapter' => $chunk->chapter,
                'region' => $chunk->region,
                'text' => $chunk->text,
                'parent_text' => $chunk->parentText,
            ],
        ];
    }

    private function assertDimensions(array $vectors): void
    {
        $expected = (int) config('rag.qdrant.vector_size');

        foreach ($vectors as $vector) {
            if (count($vector) !== $expected) {
                throw new RuntimeException(
                    "Embedding dimension mismatch. Expected {$expected}, got ".count($vector).
                    '. Update QDRANT_VECTOR_SIZE to match your embedding model.'
                );
            }
        }
    }
}
