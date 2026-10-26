<?php

namespace App\Services\Rag\Retrieval;

final readonly class RetrievalResult
{
    /**
     * @param  array<int, array>  $hits  ranked search hits (children), best first
     * @param  array<int, Passage>  $passages  what the model reads, best first
     * @param  array<string, string>  $filter  the region/chapter filter that was applied
     * @param  bool  $lowRelevance  even the best hit scored below rag.retrieval.min_rerank_score
     */
    public function __construct(
        public array $hits,
        public array $passages,
        public array $filter,
        public bool $lowRelevance = false,
    ) {}
}
