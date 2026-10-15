<?php

namespace Tests\Unit;

use App\Services\Rag\QdrantService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class QdrantServiceTest extends TestCase
{
    private const EXISTING = ['result' => ['config' => ['params' => ['vectors' => ['dense' => ['size' => 4, 'distance' => 'Cosine']]]]]];

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config([
            'rag.qdrant.url' => 'http://qdrant.test/',
            'rag.qdrant.api_key' => null,
            'rag.qdrant.collection' => 'test_collection',
            'rag.qdrant.vector_size' => 4,
            'rag.qdrant.distance' => 'Cosine',
            'rag.retrieval.rrf_k' => 42,
        ]);
    }

    private function fakeExistingCollection(array $points = []): void
    {
        Http::fake([
            'qdrant.test/collections/test_collection' => Http::response(self::EXISTING),
            'qdrant.test/collections/test_collection/points/query' => Http::response(['result' => ['points' => $points]]),
        ]);
    }

    public function test_missing_collection_is_created_with_dense_and_bm25_vectors_and_payload_indexes(): void
    {
        Http::fake(function (Request $request) {
            if ($request->method() === 'GET') {
                return Http::response(['status' => ['error' => 'Not found']], 404);
            }

            return Http::response(['result' => true]);
        });

        (new QdrantService())->ensureCollection();

        Http::assertSent(fn (Request $r) => $r->method() === 'PUT'
            && $r->url() === 'http://qdrant.test/collections/test_collection'
            && $r['vectors'] === ['dense' => ['size' => 4, 'distance' => 'Cosine']]
            && $r['sparse_vectors'] === ['bm25' => ['modifier' => 'idf']]);

        foreach (['document_id' => 'integer', 'chapter' => 'keyword', 'region' => 'keyword'] as $field => $schema) {
            Http::assertSent(fn (Request $r) => $r->url() === 'http://qdrant.test/collections/test_collection/index'
                && $r['field_name'] === $field && $r['field_schema'] === $schema);
        }
        Http::assertSentCount(5);
    }

    public function test_existing_collection_is_checked_only_once(): void
    {
        $this->fakeExistingCollection();
        $qdrant = new QdrantService();

        $qdrant->ensureCollection();
        $qdrant->ensureCollection();

        Http::assertSentCount(1);
    }

    public function test_old_single_vector_collection_is_rejected_with_a_clear_error(): void
    {
        Http::fake(['qdrant.test/*' => Http::response(['result' => ['config' => ['params' => ['vectors' => ['size' => 768, 'distance' => 'Cosine']]]]])]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('old single-vector index');

        (new QdrantService())->ensureCollection();
    }

    public function test_failed_collection_creation_throws(): void
    {
        Http::fake(fn (Request $r) => Http::response(['status' => 'error'], $r->method() === 'GET' ? 404 : 400));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to create Qdrant collection');

        (new QdrantService())->ensureCollection();
    }

    public function test_hybrid_search_prefetches_dense_and_bm25_with_filter_and_fuses_with_rrf(): void
    {
        $points = [['id' => 1, 'score' => 0.5, 'payload' => ['text' => 'a']]];
        $this->fakeExistingCollection($points);
        $sparse = ['indices' => [7], 'values' => [1.0]];

        $result = (new QdrantService())->hybridSearch([0.1, 0.2, 0.3, 0.4], $sparse, ['region' => 'South Asia', 'chapter' => 'Chapter 2'], 20, 15);

        $this->assertSame($points, $result);

        $filter = ['must' => [
            ['key' => 'region', 'match' => ['value' => 'South Asia']],
            ['key' => 'chapter', 'match' => ['value' => 'Chapter 2']],
        ]];

        Http::assertSent(function (Request $r) use ($filter, $sparse) {
            if ($r->url() !== 'http://qdrant.test/collections/test_collection/points/query') {
                return false;
            }
            [$dense, $bm25] = $r['prefetch'];

            return count($r['prefetch']) === 2
                && $dense === ['query' => [0.1, 0.2, 0.3, 0.4], 'using' => 'dense', 'limit' => 20, 'filter' => $filter]
                && $bm25 === ['query' => $sparse, 'using' => 'bm25', 'limit' => 20, 'filter' => $filter]
                && $r['query'] === ['rrf' => ['k' => 42]]
                && $r['limit'] === 15
                && $r['with_payload'] === true
                && !isset($r['filter']);
        });
    }

    public function test_hybrid_search_without_filter_sends_no_filter(): void
    {
        $this->fakeExistingCollection();

        (new QdrantService())->hybridSearch([0.1], ['indices' => [], 'values' => []], [], 5, 5);

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/points/query')
            && !array_key_exists('filter', $r['prefetch'][0]) && !array_key_exists('filter', $r['prefetch'][1]));
    }

    public function test_dense_search_uses_the_dense_vector(): void
    {
        $this->fakeExistingCollection([['id' => 3]]);

        $this->assertSame([['id' => 3]], (new QdrantService())->denseSearch([0.4, 0.3], ['region' => 'SAR'], 8));

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/points/query')
            && $r['query'] === [0.4, 0.3]
            && $r['using'] === 'dense'
            && $r['limit'] === 8
            && $r['filter'] === ['must' => [['key' => 'region', 'match' => ['value' => 'SAR']]]]
            && !isset($r['prefetch']));
    }

    public function test_search_failure_throws(): void
    {
        Http::fake([
            'qdrant.test/collections/test_collection' => Http::response(self::EXISTING),
            'qdrant.test/collections/test_collection/points/query' => Http::response('bad request', 400),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Qdrant search failed');

        (new QdrantService())->denseSearch([0.1], [], 5);
    }

    public function test_delete_by_document_filters_on_document_id(): void
    {
        Http::fake([
            'qdrant.test/collections/test_collection' => Http::response(self::EXISTING),
            'qdrant.test/collections/test_collection/points/delete*' => Http::response(['result' => true]),
        ]);

        (new QdrantService())->deleteByDocument(12);

        Http::assertSent(fn (Request $r) => $r->url() === 'http://qdrant.test/collections/test_collection/points/delete?wait=true'
            && $r['filter'] === ['must' => [['key' => 'document_id', 'match' => ['value' => 12]]]]);
    }

    public function test_api_key_header_is_sent_when_configured(): void
    {
        config(['rag.qdrant.api_key' => 'secret']);
        $this->fakeExistingCollection();

        (new QdrantService())->ensureCollection();

        Http::assertSent(fn (Request $r) => $r->hasHeader('api-key', 'secret'));
    }
}
