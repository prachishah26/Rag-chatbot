<?php

namespace App\Services\Rag\Retrieval;

use App\Services\Rag\QdrantService;
use App\Services\Rag\Reranker;

/**
 * The online retrieval half, on every question.
 *  naive:    dense search → top_k chunks.
 *  advanced: route to a region/chapter filter → hybrid dense + BM25 search fused with RRF
 *            → cross-encoder rerank of the pool → the parent sections of the best children.
 */
class Retriever
{
    public const NAIVE = 'naive';
    public const ADVANCED = 'advanced';

    public function __construct(
        private Embedder $embedder,
        private QdrantService $qdrant,
        private Bm25Encoder $bm25,
        private QueryRouter $router,
        private CrossEncoderReranker $crossEncoder,
        private Reranker $lexical = new Reranker(),
    ) {}

    public function retrieve(string $question, string $pipeline = self::ADVANCED): RetrievalResult
    {
        return $pipeline === self::NAIVE ? $this->naive($question) : $this->advanced($question);
    }

    private function naive(string $question): RetrievalResult
    {
        $pool = max($this->topK(), (int) config('rag.retrieval.candidate_pool'));
        $hits = $this->qdrant->denseSearch($this->embedder->embedQuery($question), [], $pool);

        return new RetrievalResult(
            $hits,
            array_map(fn (array $hit) => Passage::fromHit($hit, useParent: false), array_slice($hits, 0, $this->topK())),
            []
        );
    }

    private function advanced(string $question): RetrievalResult
    {
        $pool = max($this->topK(), (int) config('rag.retrieval.candidate_pool'));
        $filter = $this->router->route($question);
        $dense = $this->embedder->embedQuery($question);
        $sparse = $this->bm25->encodeQuery($question);

        $hits = $this->qdrant->hybridSearch($dense, $sparse, $filter, $pool, $pool);

        // A filter that matches nothing (another document, an unknown region) must not hide everything.
        if (!$hits && $filter) {
            $filter = [];
            $hits = $this->qdrant->hybridSearch($dense, $sparse, [], $pool, $pool);
        }

        $hits = $this->rerank($question, $hits);

        return new RetrievalResult($hits, $this->parents($hits), $filter);
    }

    private function rerank(string $question, array $hits): array
    {
        return match ((string) config('rag.retrieval.reranker')) {
            'none' => $hits,
            'lexical' => $this->lexical->rerank($question, $hits),
            default => $this->crossEncoder->rerank($question, $hits),
        };
    }

    /** The best children's parents, each once, in the children's order. */
    private function parents(array $hits): array
    {
        $passages = [];
        $seen = [];

        foreach ($hits as $hit) {
            $payload = $hit['payload'] ?? [];
            $key = ($payload['document_id'] ?? '').':'.($payload['parent_index'] ?? $payload['chunk_index'] ?? '');

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $passages[] = Passage::fromHit($hit, useParent: true);

            if (count($passages) >= $this->topK()) {
                break;
            }
        }

        return $passages;
    }

    private function topK(): int
    {
        return max(1, (int) config('rag.retrieval.top_k'));
    }
}
