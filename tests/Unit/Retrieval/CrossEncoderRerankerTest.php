<?php

namespace Tests\Unit\Retrieval;

use App\Services\Rag\Retrieval\CrossEncoderReranker;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class CrossEncoderRerankerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config(['rag.retrieval.reranker_url' => 'http://reranker.test/']);
    }

    private function hits(): array
    {
        return [
            ['id' => 1, 'score' => 0.9, 'payload' => ['heading_path' => 'Chapter 2 > South Asia', 'text' => 'Growth 6.3 percent.']],
            ['id' => 2, 'score' => 0.8, 'payload' => ['text' => 'Unrelated.']],
            ['id' => 3, 'score' => 0.7, 'payload' => ['heading_path' => '', 'text' => 'Inflation fell.']],
        ];
    }

    public function test_passages_include_heading_path_and_hits_come_back_sorted_by_score(): void
    {
        Http::fake(['reranker.test/rerank' => Http::response([
            ['index' => 1, 'score' => -3.5],
            ['index' => 2, 'score' => 4.25],
            ['index' => 0, 'score' => 1.0],
        ])]);

        $ranked = (new CrossEncoderReranker())->rerank('SAR growth?', $this->hits());

        $this->assertSame([3, 1, 2], array_column($ranked, 'id'));
        $this->assertSame([4.25, 1.0, -3.5], array_column($ranked, 'rerank_score'));
        $this->assertSame(0.7, $ranked[0]['score']);

        Http::assertSent(fn (Request $r) => $r->url() === 'http://reranker.test/rerank'
            && $r['query'] === 'SAR growth?'
            && $r['texts'] === ["Chapter 2 > South Asia\nGrowth 6.3 percent.", 'Unrelated.', 'Inflation fell.']);
    }

    public function test_no_hits_makes_no_request(): void
    {
        Http::fake();

        $this->assertSame([], (new CrossEncoderReranker())->rerank('q', []));
        Http::assertNothingSent();
    }

    public function test_unknown_index_throws(): void
    {
        $hits = $this->hits();
        $reply = array_map(fn (int $i) => ['index' => $i, 'score' => 1.0], array_keys($hits));
        $reply[0]['index'] = 7;
        Http::fake(['reranker.test/*' => Http::response($reply)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unknown passage index');

        (new CrossEncoderReranker())->rerank('q', $hits);
    }

    public function test_short_reply_throws_instead_of_looking_like_no_results(): void
    {
        Http::fake(['reranker.test/*' => Http::response([])]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Reranker returned 0 scores');

        (new CrossEncoderReranker())->rerank('q', $this->hits());
    }

    public function test_http_error_throws(): void
    {
        Http::fake(['reranker.test/*' => Http::response(['error' => 'boom'], 500)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Reranker failed (HTTP 500)');

        (new CrossEncoderReranker())->rerank('q', $this->hits());
    }
}
