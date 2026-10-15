<?php

namespace Tests\Unit\Retrieval;

use App\Services\Rag\Retrieval\TeiEmbedder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class TeiEmbedderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config([
            'rag.embeddings.tei_url' => 'http://tei.test/',
            'rag.embeddings.batch_size' => 2,
            'rag.embeddings.timeout' => 5,
        ]);
    }

    /** Replies with one vector per input, whose first value is the input's length. */
    private function fakeEcho(): void
    {
        Http::fake(['tei.test/embed' => fn (Request $request) => Http::response(
            array_map(fn (string $input) => [strlen($input), 0.5], $request['inputs'])
        )]);
    }

    public function test_documents_are_embedded_in_batches_of_the_configured_size(): void
    {
        $this->fakeEcho();

        $vectors = (new TeiEmbedder())->embedDocuments(['a', 'bb', 'ccc', 'dddd', 'eeeee']);

        $this->assertSame([[1.0, 0.5], [2.0, 0.5], [3.0, 0.5], [4.0, 0.5], [5.0, 0.5]], $vectors);
        Http::assertSentCount(3);
        Http::assertSent(fn (Request $r) => $r->url() === 'http://tei.test/embed'
            && $r['inputs'] === ['a', 'bb'] && $r['truncate'] === true);
        Http::assertSent(fn (Request $r) => $r['inputs'] === ['eeeee']);
    }

    public function test_query_gets_the_search_instruction_and_documents_do_not(): void
    {
        $this->fakeEcho();
        $embedder = new TeiEmbedder();

        $vector = $embedder->embedQuery('growth in SAR');
        $embedder->embedDocuments(['growth in SAR']);

        $this->assertSame([(float) strlen(TeiEmbedder::QUERY_INSTRUCTION.'growth in SAR'), 0.5], $vector);
        Http::assertSent(fn (Request $r) => $r['inputs'] === [TeiEmbedder::QUERY_INSTRUCTION.'growth in SAR']);
        Http::assertSent(fn (Request $r) => $r['inputs'] === ['growth in SAR']);
    }

    public function test_http_error_throws(): void
    {
        Http::fake(['tei.test/*' => Http::response(['error' => 'model not loaded'], 503)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Embedding service failed (HTTP 503): model not loaded');

        (new TeiEmbedder())->embedQuery('q');
    }

    public function test_wrong_vector_count_throws(): void
    {
        Http::fake(['tei.test/*' => Http::response([[0.1, 0.2]])]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('invalid response');

        (new TeiEmbedder())->embedDocuments(['one', 'two']);
    }
}
