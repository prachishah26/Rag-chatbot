<?php

namespace Tests\Unit\Retrieval;

use App\Services\Rag\QdrantService;
use App\Services\Rag\Reranker;
use App\Services\Rag\Retrieval\Bm25Encoder;
use App\Services\Rag\Retrieval\CrossEncoderReranker;
use App\Services\Rag\Retrieval\Embedder;
use App\Services\Rag\Retrieval\QueryRouter;
use App\Services\Rag\Retrieval\Retriever;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class RetrieverTest extends TestCase
{
    private const DENSE = [0.1, 0.2, 0.3];
    private const SPARSE = ['indices' => [1, 2], 'values' => [1.0, 1.0]];

    private MockInterface $embedder;
    private MockInterface $qdrant;
    private MockInterface $bm25;
    private MockInterface $router;
    private MockInterface $crossEncoder;
    private MockInterface $lexical;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'rag.retrieval.top_k' => 2,
            'rag.retrieval.candidate_pool' => 10,
            'rag.retrieval.reranker' => 'none',
        ]);

        $this->embedder = Mockery::mock(Embedder::class);
        $this->embedder->shouldReceive('embedQuery')->with('question')->andReturn(self::DENSE);
        $this->qdrant = Mockery::mock(QdrantService::class);
        $this->bm25 = Mockery::mock(Bm25Encoder::class);
        $this->bm25->shouldReceive('encodeQuery')->with('question')->andReturn(self::SPARSE);
        $this->router = Mockery::mock(QueryRouter::class);
        $this->crossEncoder = Mockery::mock(CrossEncoderReranker::class);
        $this->lexical = Mockery::mock(Reranker::class);
    }

    private function retriever(): Retriever
    {
        return new Retriever($this->embedder, $this->qdrant, $this->bm25, $this->router, $this->crossEncoder, $this->lexical);
    }

    private function hit(int $chunk, int $parent, int $documentId = 1, float $score = 0.5): array
    {
        return [
            'id' => $documentId * 1000 + $chunk,
            'score' => $score,
            'payload' => [
                'document_id' => $documentId,
                'document_name' => "doc{$documentId}.pdf",
                'chunk_index' => $chunk,
                'parent_index' => $parent,
                'text' => "child {$chunk}",
                'parent_text' => "parent {$parent} of doc {$documentId}",
            ],
        ];
    }

    public function test_naive_uses_dense_search_and_returns_top_k_children(): void
    {
        $hits = [$this->hit(1, 0), $this->hit(2, 0), $this->hit(3, 1)];
        $this->qdrant->shouldReceive('denseSearch')->once()->with(self::DENSE, [], 10)->andReturn($hits);
        $this->router->shouldNotReceive('route');
        $this->qdrant->shouldNotReceive('hybridSearch');

        $result = $this->retriever()->retrieve('question', Retriever::NAIVE);

        $this->assertSame($hits, $result->hits);
        $this->assertSame(['child 1', 'child 2'], array_map(fn ($p) => $p->text, $result->passages));
        $this->assertSame([], $result->filter);
    }

    public function test_pool_is_at_least_top_k(): void
    {
        config(['rag.retrieval.top_k' => 15]);
        $this->qdrant->shouldReceive('denseSearch')->once()->with(self::DENSE, [], 15)->andReturn([]);

        $this->assertSame([], $this->retriever()->retrieve('question', Retriever::NAIVE)->passages);
    }

    public function test_advanced_passes_router_filter_to_hybrid_search(): void
    {
        $filter = ['region' => 'South Asia'];
        $this->router->shouldReceive('route')->with('question')->andReturn($filter);
        $this->qdrant->shouldReceive('hybridSearch')->once()->with(self::DENSE, self::SPARSE, $filter, 10, 10)
            ->andReturn([$this->hit(1, 0)]);

        $result = $this->retriever()->retrieve('question');

        $this->assertSame($filter, $result->filter);
        $this->assertSame(['parent 0 of doc 1'], array_map(fn ($p) => $p->text, $result->passages));
    }

    public function test_advanced_retries_without_filter_when_filtered_search_is_empty(): void
    {
        $this->router->shouldReceive('route')->andReturn(['chapter' => 'Chapter 9']);
        $this->qdrant->shouldReceive('hybridSearch')->once()->with(self::DENSE, self::SPARSE, ['chapter' => 'Chapter 9'], 10, 10)->andReturn([]);
        $this->qdrant->shouldReceive('hybridSearch')->once()->with(self::DENSE, self::SPARSE, [], 10, 10)->andReturn([$this->hit(1, 0)]);

        $result = $this->retriever()->retrieve('question');

        $this->assertSame([], $result->filter);
        $this->assertCount(1, $result->passages);
    }

    public function test_naive_searches_only_the_given_documents(): void
    {
        $this->qdrant->shouldReceive('denseSearch')->once()->with(self::DENSE, ['document_id' => [4, 9]], 10)->andReturn([]);

        $this->retriever()->retrieve('question', Retriever::NAIVE, [4, 9]);
    }

    public function test_advanced_combines_document_scope_with_router_filter(): void
    {
        $this->router->shouldReceive('route')->andReturn(['region' => 'South Asia']);
        $this->qdrant->shouldReceive('hybridSearch')->once()
            ->with(self::DENSE, self::SPARSE, ['region' => 'South Asia', 'document_id' => [4]], 10, 10)
            ->andReturn([$this->hit(1, 0, 4)]);

        $result = $this->retriever()->retrieve('question', Retriever::ADVANCED, [4]);

        // The reported filter is the router's; the scope is the caller's own choice.
        $this->assertSame(['region' => 'South Asia'], $result->filter);
    }

    public function test_retry_without_router_filter_keeps_the_document_scope(): void
    {
        $this->router->shouldReceive('route')->andReturn(['chapter' => 'Chapter 9']);
        $this->qdrant->shouldReceive('hybridSearch')->once()
            ->with(self::DENSE, self::SPARSE, ['chapter' => 'Chapter 9', 'document_id' => [4]], 10, 10)->andReturn([]);
        $this->qdrant->shouldReceive('hybridSearch')->once()
            ->with(self::DENSE, self::SPARSE, ['document_id' => [4]], 10, 10)->andReturn([$this->hit(1, 0, 4)]);

        $result = $this->retriever()->retrieve('question', Retriever::ADVANCED, [4]);

        $this->assertSame([], $result->filter);
        $this->assertCount(1, $result->passages);
    }

    public function test_empty_document_scope_searches_nothing(): void
    {
        $this->router->shouldNotReceive('route');
        $this->qdrant->shouldNotReceive('hybridSearch');
        $this->qdrant->shouldNotReceive('denseSearch');

        $this->assertSame([], $this->retriever()->retrieve('question', Retriever::ADVANCED, [])->passages);
        $this->assertSame([], $this->retriever()->retrieve('question', Retriever::NAIVE, [])->passages);
    }

    public function test_advanced_without_filter_does_not_retry(): void
    {
        $this->router->shouldReceive('route')->andReturn([]);
        $this->qdrant->shouldReceive('hybridSearch')->once()->andReturn([]);

        $result = $this->retriever()->retrieve('question');

        $this->assertSame([], $result->hits);
        $this->assertSame([], $result->passages);
    }

    public function test_cross_encoder_setting_reranks_with_the_cross_encoder(): void
    {
        config(['rag.retrieval.reranker' => 'cross_encoder']);
        $hits = [$this->hit(1, 0), $this->hit(2, 1)];
        $reranked = [[...$hits[1], 'rerank_score' => 5.0], [...$hits[0], 'rerank_score' => 1.0]];

        $this->router->shouldReceive('route')->andReturn([]);
        $this->qdrant->shouldReceive('hybridSearch')->andReturn($hits);
        $this->crossEncoder->shouldReceive('rerank')->once()->with('question', $hits)->andReturn($reranked);
        $this->lexical->shouldNotReceive('rerank');

        $result = $this->retriever()->retrieve('question');

        $this->assertSame($reranked, $result->hits);
        $this->assertSame(['parent 1 of doc 1', 'parent 0 of doc 1'], array_map(fn ($p) => $p->text, $result->passages));
        $this->assertSame(5.0, $result->passages[0]->score);
    }

    private function tableHit(int $chunk, int $parent, string $rows): array
    {
        $hit = $this->hit($chunk, $parent);
        $hit['payload']['type'] = 'table';
        $hit['payload']['text'] = "Table: Forecasts\n|  | 2026f | 2027f |\n| --- | --- | --- |\n".$rows;

        return $hit;
    }

    public function test_a_table_whose_row_the_question_names_is_kept_below_the_rerank_cut(): void
    {
        config(['rag.retrieval.top_k' => 3]);
        $this->embedder->shouldReceive('embedQuery')->andReturn(self::DENSE);
        $this->bm25->shouldReceive('encodeQuery')->andReturn(self::SPARSE);
        $question = "What is Pakistan's growth forecast for 2026?";
        $hits = [
            $this->hit(1, 0), $this->hit(2, 1), $this->hit(3, 1), $this->hit(4, 2),
            $this->tableHit(5, 3, '| Egypt | 4.6 | 4.0 |'),
            $this->tableHit(6, 4, "| Egypt | 4.6 | 4.0 |\n| Pakistan 1 | 3.0 | 3.2 |"),
        ];
        $this->router->shouldReceive('route')->andReturn([]);
        $this->qdrant->shouldReceive('hybridSearch')->andReturn($hits);

        $result = $this->retriever()->retrieve($question);

        // The prose ranked above it keeps every slot but the last.
        $this->assertSame(
            ['parent 0 of doc 1', 'parent 1 of doc 1', 'parent 4 of doc 1'],
            array_map(fn ($p) => $p->text, $result->passages)
        );
    }

    public function test_a_named_table_already_in_the_top_k_is_left_where_it_is(): void
    {
        $this->embedder->shouldReceive('embedQuery')->andReturn(self::DENSE);
        $this->bm25->shouldReceive('encodeQuery')->andReturn(self::SPARSE);
        $hits = [$this->tableHit(1, 0, '| Pakistan 1 | 3.0 | 3.2 |'), $this->hit(2, 1), $this->hit(3, 2)];
        $this->router->shouldReceive('route')->andReturn([]);
        $this->qdrant->shouldReceive('hybridSearch')->andReturn($hits);

        $result = $this->retriever()->retrieve('Pakistan growth?');

        $this->assertSame($hits, $result->hits);
    }

    public function test_bullets_and_footnote_markers_are_not_part_of_a_row_label(): void
    {
        $this->embedder->shouldReceive('embedQuery')->andReturn(self::DENSE);
        $this->bm25->shouldReceive('encodeQuery')->andReturn(self::SPARSE);
        $table = $this->tableHit(3, 2, "| • Production 2 | 50% |");
        $hits = [$this->hit(1, 0), $this->hit(2, 1), $table];
        $this->router->shouldReceive('route')->andReturn([]);
        $this->qdrant->shouldReceive('hybridSearch')->andReturn($hits);

        $result = $this->retriever()->retrieve('What share comes from production?');

        $this->assertSame([$hits[0], $table, $hits[1]], $result->hits);
    }

    public function test_a_row_label_matches_its_words_in_any_order_and_form(): void
    {
        $this->embedder->shouldReceive('embedQuery')->andReturn(self::DENSE);
        $this->bm25->shouldReceive('encodeQuery')->andReturn(self::SPARSE);
        $table = $this->tableHit(3, 2, "| Secure credentials produced | 5,014,573 |\n| Passports produced | 15,901,600 |");
        $hits = [$this->hit(1, 0), $this->hit(2, 1), $table];
        $this->router->shouldReceive('route')->andReturn([]);
        $this->qdrant->shouldReceive('hybridSearch')->andReturn($hits);

        $result = $this->retriever()->retrieve('How many passports did GPO produce in FY 2018?');

        $this->assertSame([$hits[0], $table, $hits[1]], $result->hits);
    }

    public function test_a_row_label_with_a_word_missing_from_the_question_does_not_match(): void
    {
        $this->embedder->shouldReceive('embedQuery')->andReturn(self::DENSE);
        $this->bm25->shouldReceive('encodeQuery')->andReturn(self::SPARSE);
        $hits = [$this->hit(1, 0), $this->hit(2, 1), $this->tableHit(3, 2, '| Secure credentials produced | 5,014,573 |')];
        $this->router->shouldReceive('route')->andReturn([]);
        $this->qdrant->shouldReceive('hybridSearch')->andReturn($hits);

        $this->assertSame($hits, $this->retriever()->retrieve('How many passports did GPO produce?')->hits);
    }

    public function test_a_header_row_label_does_not_promote_a_table(): void
    {
        $this->embedder->shouldReceive('embedQuery')->andReturn(self::DENSE);
        $this->bm25->shouldReceive('encodeQuery')->andReturn(self::SPARSE);
        $table = $this->hit(3, 2);
        $table['payload']['type'] = 'table';
        $table['payload']['text'] = "| Country | 2026f |\n| --- | --- |\n| Egypt | 4.6 |";
        $hits = [$this->hit(1, 0), $this->hit(2, 1), $table];
        $this->router->shouldReceive('route')->andReturn([]);
        $this->qdrant->shouldReceive('hybridSearch')->andReturn($hits);

        $this->assertSame($hits, $this->retriever()->retrieve('Which country grew fastest?')->hits);
    }

    public function test_short_or_unnamed_row_labels_do_not_promote_a_table(): void
    {
        $this->embedder->shouldReceive('embedQuery')->andReturn(self::DENSE);
        $this->bm25->shouldReceive('encodeQuery')->andReturn(self::SPARSE);
        $hits = [$this->hit(1, 0), $this->hit(2, 1), $this->tableHit(3, 2, "| UK | 1.0 | 1.1 |\n| Egypt | 4.6 | 4.0 |")];
        $this->router->shouldReceive('route')->andReturn([]);
        $this->qdrant->shouldReceive('hybridSearch')->andReturn($hits);

        $this->assertSame($hits, $this->retriever()->retrieve('What is UK growth in Pakistan?')->hits);
    }

    public function test_lexical_setting_reranks_with_the_lexical_reranker(): void
    {
        config(['rag.retrieval.reranker' => 'lexical']);
        $hits = [$this->hit(1, 0), $this->hit(2, 1)];

        $this->router->shouldReceive('route')->andReturn([]);
        $this->qdrant->shouldReceive('hybridSearch')->andReturn($hits);
        $this->lexical->shouldReceive('rerank')->once()->with('question', $hits)->andReturn(array_reverse($hits));
        $this->crossEncoder->shouldNotReceive('rerank');

        $this->assertSame(array_reverse($hits), $this->retriever()->retrieve('question')->hits);
    }

    public function test_none_setting_keeps_fused_order(): void
    {
        $hits = [$this->hit(1, 0), $this->hit(2, 1)];

        $this->router->shouldReceive('route')->andReturn([]);
        $this->qdrant->shouldReceive('hybridSearch')->andReturn($hits);
        $this->lexical->shouldNotReceive('rerank');
        $this->crossEncoder->shouldNotReceive('rerank');

        $this->assertSame($hits, $this->retriever()->retrieve('question')->hits);
    }

    public function test_parents_are_deduplicated_per_document_and_capped_at_top_k(): void
    {
        config(['rag.retrieval.top_k' => 3]);
        $hits = [
            $this->hit(1, 0, documentId: 1),
            $this->hit(2, 0, documentId: 1), // same parent: skipped
            $this->hit(1, 0, documentId: 2), // same parent index, other document: kept
            $this->hit(5, 2, documentId: 1),
            $this->hit(9, 4, documentId: 1), // over top_k
        ];

        $this->router->shouldReceive('route')->andReturn([]);
        $this->qdrant->shouldReceive('hybridSearch')->andReturn($hits);

        $passages = $this->retriever()->retrieve('question')->passages;

        $this->assertSame(
            ['parent 0 of doc 1', 'parent 0 of doc 2', 'parent 2 of doc 1'],
            array_map(fn ($p) => $p->text, $passages)
        );
        $this->assertSame([1, 2, 1], array_map(fn ($p) => $p->documentId, $passages));
    }
}
