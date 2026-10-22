<?php

namespace App\Services\Rag;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * One Qdrant collection; every chunk has two vectors — "dense" (meaning) and "bm25" (exact words,
 * weighted by Qdrant's IDF) — plus metadata (document, chapter, region, heading path, parent).
 */
class QdrantService
{
    public const DENSE = 'dense';
    public const SPARSE = 'bm25';

    // Payload fields that searches filter on.
    private const INDEXED_FIELDS = ['document_id' => 'integer', 'chapter' => 'keyword', 'region' => 'keyword'];

    private bool $collectionReady = false;

    protected function client(): PendingRequest
    {
        $client = Http::baseUrl(rtrim(config('rag.qdrant.url'), '/'))
            ->acceptJson()
            ->timeout(30);

        if ($key = config('rag.qdrant.api_key')) {
            $client = $client->withHeaders(['api-key' => $key]);
        }

        return $client;
    }

    public function ensureCollection(): void
    {
        if ($this->collectionReady) {
            return;
        }

        $name = config('rag.qdrant.collection');
        $check = $this->client()->get("/collections/{$name}");

        if ($check->successful()) {
            if (data_get($check->json(), 'result.config.params.vectors.'.self::DENSE) === null) {
                throw new RuntimeException(
                    "Qdrant collection \"{$name}\" was created for the old single-vector index. ".
                    'Set QDRANT_COLLECTION to a new name and run `php artisan rag:reindex`.'
                );
            }
            $this->collectionReady = true;

            return;
        }

        $response = $this->client()->put("/collections/{$name}", [
            'vectors' => [
                self::DENSE => [
                    'size' => (int) config('rag.qdrant.vector_size'),
                    'distance' => config('rag.qdrant.distance'),
                ],
            ],
            'sparse_vectors' => [
                self::SPARSE => ['modifier' => 'idf'],
            ],
        ]);

        if ($response->failed()) {
            throw new RuntimeException('Unable to create Qdrant collection: '.$response->body());
        }

        foreach (self::INDEXED_FIELDS as $field => $schema) {
            $index = $this->client()->put("/collections/{$name}/index", ['field_name' => $field, 'field_schema' => $schema]);
            if ($index->failed()) {
                throw new RuntimeException("Unable to index Qdrant field {$field}: ".$index->body());
            }
        }

        $this->collectionReady = true;
    }

    public function upsert(array $points): void
    {
        $this->ensureCollection();

        $response = $this->client()->put(
            '/collections/'.config('rag.qdrant.collection').'/points?wait=true',
            ['points' => $points]
        );

        if ($response->failed()) {
            throw new RuntimeException('Qdrant upsert failed: '.$response->body());
        }
    }

    public function deleteByDocument(int $documentId): void
    {
        $this->ensureCollection();

        $response = $this->client()->post(
            '/collections/'.config('rag.qdrant.collection').'/points/delete?wait=true',
            ['filter' => ['must' => [['key' => 'document_id', 'match' => ['value' => $documentId]]]]]
        );

        if ($response->failed()) {
            throw new RuntimeException('Qdrant delete failed: '.$response->body());
        }
    }

    /**
     * Dense and BM25 search, each taking $pool hits, merged by Reciprocal Rank Fusion: a chunk
     * scores 1 / (k + rank) in every list it appears in. Only ranks count, so a cosine similarity
     * and a BM25 score never need a common scale.
     *
     * @param  array<string, string|int[]>  $filter  payload field => exact value, or a list of allowed values
     */
    public function hybridSearch(array $dense, array $sparse, array $filter, int $pool, int $limit): array
    {
        $qdrantFilter = $this->filter($filter);

        return $this->query([
            'prefetch' => [
                ['query' => $dense, 'using' => self::DENSE, 'limit' => $pool, ...$qdrantFilter],
                ['query' => $sparse, 'using' => self::SPARSE, 'limit' => $pool, ...$qdrantFilter],
            ],
            'query' => ['rrf' => ['k' => (int) config('rag.retrieval.rrf_k')]],
            'limit' => $limit,
            'with_payload' => true,
        ]);
    }

    /** Dense similarity only: the naive pipeline. */
    public function denseSearch(array $dense, array $filter, int $limit): array
    {
        return $this->query([
            'query' => $dense,
            'using' => self::DENSE,
            'limit' => $limit,
            'with_payload' => true,
            ...$this->filter($filter),
        ]);
    }

    private function query(array $body): array
    {
        $this->ensureCollection();

        $response = $this->client()->post('/collections/'.config('rag.qdrant.collection').'/points/query', $body);

        if ($response->failed()) {
            throw new RuntimeException('Qdrant search failed: '.$response->body());
        }

        return data_get($response->json(), 'result.points', []);
    }

    /** @return array{filter?: array} */
    private function filter(array $filter): array
    {
        $must = [];
        foreach ($filter as $field => $value) {
            $must[] = ['key' => $field, 'match' => is_array($value) ? ['any' => array_values($value)] : ['value' => $value]];
        }

        return $must ? ['filter' => ['must' => $must]] : [];
    }
}
