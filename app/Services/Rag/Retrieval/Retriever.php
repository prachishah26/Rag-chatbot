<?php

namespace App\Services\Rag\Retrieval;

use App\Services\Rag\Indexing\ContentsListing;
use App\Services\Rag\QdrantService;
use App\Services\Rag\Reranker;
use App\Services\Rag\TableParser;

/**
 * The online retrieval half, on every question.
 *  naive:    dense search → top_k chunks.
 *  advanced: route to a region/chapter filter → hybrid dense + BM25 search fused with RRF
 *            → cross-encoder rerank of the pool → the parent sections of the best children.
 *            A table whose row the question names keeps the last slot: the cross-encoder, trained
 *            on prose, ranks "| Pakistan 1 | 3.0 | 3.2 |" below any paragraph about the region.
 */
class Retriever
{
    public const NAIVE = 'naive';
    public const ADVANCED = 'advanced';

    // A row label shorter than this ("UK", "Q1") matches too many questions by accident.
    private const MIN_ROW_LABEL_LETTERS = 4;

    // Joining words a row label may hold that the question need not repeat.
    private const LABEL_STOPWORDS = ['and', 'the', 'for', 'from', 'with', 'per'];

    public function __construct(
        private Embedder $embedder,
        private QdrantService $qdrant,
        private Bm25Encoder $bm25,
        private QueryRouter $router,
        private CrossEncoderReranker $crossEncoder,
        private Reranker $lexical = new Reranker(),
        private TableParser $tables = new TableParser(),
        private BroadQuestion $broad = new BroadQuestion(),
        private ContentsListing $contents = new ContentsListing(),
    ) {}

    /**
     * @param  int[]|null  $documentIds  the documents to search; null searches the whole index (CLI, evaluation)
     */
    public function retrieve(string $question, string $pipeline = self::ADVANCED, ?array $documentIds = null): RetrievalResult
    {
        if ($documentIds === []) {
            return new RetrievalResult([], [], []);
        }

        $scope = $documentIds === null ? [] : ['document_id' => array_values($documentIds)];

        return $pipeline === self::NAIVE ? $this->naive($question, $scope) : $this->advanced($question, $scope);
    }

    private function naive(string $question, array $scope): RetrievalResult
    {
        $pool = max($this->topK(), (int) config('rag.retrieval.candidate_pool'));
        $hits = $this->withoutListings($this->qdrant->denseSearch($this->embedder->embedQuery($question), $scope, $pool));

        return new RetrievalResult(
            $hits,
            array_map(fn (array $hit) => Passage::fromHit($hit, useParent: false), array_slice($hits, 0, $this->topK())),
            []
        );
    }

    private function advanced(string $question, array $scope): RetrievalResult
    {
        $pool = max($this->topK(), (int) config('rag.retrieval.candidate_pool'));
        $broad = $this->broad->isBroad($question);
        // An overview request names no topic: search for the summaries instead of its generic words.
        $search = $broad ? $question.' '.BroadQuestion::SEARCH_HINT : $question;
        $filter = $this->router->route($question);
        $dense = $this->embedder->embedQuery($search);
        $sparse = $this->bm25->encodeQuery($search);

        $hits = $this->qdrant->hybridSearch($dense, $sparse, [...$filter, ...$scope], $pool, $pool);

        // A filter that matches nothing (another document, an unknown region) must not hide everything.
        // The document scope stays: it decides what the user may see, not what the question meant.
        if (!$hits && $filter) {
            $filter = [];
            $hits = $this->qdrant->hybridSearch($dense, $sparse, $scope, $pool, $pool);
        }

        $hits = $this->rerank($search, $this->withoutListings($hits));
        $hits = $broad ? $this->summariesFirst($hits) : $this->keepNamedTable($question, $hits);

        // Broad questions score low by nature; their summaries are still the right passages.
        $lowRelevance = !$broad && $this->isLowRelevance($hits);

        return new RetrievalResult($hits, $this->parents($hits), $filter, $lowRelevance);
    }

    /** Tables of contents name every topic, so they match broad questions while answering none. */
    private function withoutListings(array $hits): array
    {
        return array_values(array_filter($hits, fn (array $hit) => !$this->contents->isListing(
            (string) ($hit['payload']['text'] ?? ''),
            (string) ($hit['payload']['heading_path'] ?? '')
        )));
    }

    /** Hits from summary sections first, each group keeping its rerank order. */
    private function summariesFirst(array $hits): array
    {
        $isSummary = fn (array $hit) => $this->broad->isSummaryHeading((string) ($hit['payload']['heading_path'] ?? ''));

        return [...array_filter($hits, $isSummary), ...array_filter($hits, fn (array $hit) => !$isSummary($hit))];
    }

    /** The cross-encoder rates even the best hit as unrelated: the question is off topic or too vague. */
    private function isLowRelevance(array $hits): bool
    {
        $min = config('rag.retrieval.min_rerank_score');

        if (!$hits || $min === null || (string) config('rag.retrieval.reranker') !== 'cross_encoder') {
            return false;
        }

        return max(array_map(fn (array $hit) => (float) ($hit['rerank_score'] ?? PHP_FLOAT_MAX), $hits)) < (float) $min;
    }

    private function rerank(string $question, array $hits): array
    {
        return match ((string) config('rag.retrieval.reranker')) {
            'none' => $hits,
            'lexical' => $this->lexical->rerank($question, $hits),
            default => $this->crossEncoder->rerank($question, $hits),
        };
    }

    /** Moves the best table hit naming a row from the question into the last passage slot, if it is below the cut. */
    private function keepNamedTable(string $question, array $hits): array
    {
        $cut = $this->lastSlotIndex($hits);

        if ($cut === null) {
            return $hits;
        }

        foreach ($hits as $i => $hit) {
            if (!$this->namesTableRow($question, $hit)) {
                continue;
            }

            // Already in the passages (or first in line for the last slot): nothing to move.
            if ($i <= $cut) {
                return $hits;
            }

            return [...array_slice($hits, 0, $cut), $hit, ...array_slice($hits, $cut, $i - $cut), ...array_slice($hits, $i + 1)];
        }

        return $hits;
    }

    /** Index of the first hit of the top_k-th distinct parent, or null when there are no more parents than that. */
    private function lastSlotIndex(array $hits): ?int
    {
        $seen = [];

        foreach ($hits as $i => $hit) {
            $seen[$this->parentKey($hit)] = true;

            if (count($seen) === $this->topK()) {
                return $i;
            }
        }

        return null;
    }

    private function namesTableRow(string $question, array $hit): bool
    {
        if (($hit['payload']['type'] ?? null) !== 'table') {
            return false;
        }

        $lines = explode("\n", (string) ($hit['payload']['text'] ?? ''));

        foreach ($lines as $i => $line) {
            if (!str_starts_with($line, '|') || $this->tables->isSeparator($line)) {
                continue;
            }

            $cells = $this->tables->markdownCells($line);

            // A column header ("| Country | 2026f |") names no row; "| • Production | 50% |" above a
            // separator is a data row that the parser promoted to header.
            if ($this->tables->isSeparator($lines[$i + 1] ?? '') && $this->tables->isHeaderRow($cells)) {
                continue;
            }

            // "| Pakistan 1 | 3.0 |" → "Pakistan", "| • Production |" → "Production": bullets and
            // footnote markers are not part of the name.
            $label = trim(preg_replace(['/^[^\pL\pN]+/u', '/(\s+\d+)+$/'], '', $cells[0] ?? ''));

            if (preg_match_all('/\pL/u', $label) >= self::MIN_ROW_LABEL_LETTERS && $this->asksFor($question, $label)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every word of the label appears in the question, in any order and form: "Passports produced"
     * is asked for by "How many passports did GPO produce?".
     */
    private function asksFor(string $question, string $label): bool
    {
        $asked = array_flip(array_map(fn (string $word) => $this->stem($word), $this->words($question)));
        $needed = array_filter($this->words($label), fn (string $word) => !in_array($word, self::LABEL_STOPWORDS, true));

        foreach ($needed as $word) {
            if (!isset($asked[$this->stem($word)])) {
                return false;
            }
        }

        return $needed !== [];
    }

    /** @return string[] lower-case words */
    private function words(string $text): array
    {
        return preg_split('/[^\pL\pN]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY);
    }

    /** "produced", "produces", "produce" → "produc"; crude, but applied alike to both sides. */
    private function stem(string $word): string
    {
        return mb_strlen($word) > 4 ? preg_replace('/(?:ing|ed|es|s|e)$/u', '', $word) : $word;
    }

    /** The best children's parents, each once, in the children's order. */
    private function parents(array $hits): array
    {
        $passages = [];
        $seen = [];

        foreach ($hits as $hit) {
            $key = $this->parentKey($hit);

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

    private function parentKey(array $hit): string
    {
        $payload = $hit['payload'] ?? [];

        return ($payload['document_id'] ?? '').':'.($payload['parent_index'] ?? $payload['chunk_index'] ?? '');
    }

    private function topK(): int
    {
        return max(1, (int) config('rag.retrieval.top_k'));
    }
}
