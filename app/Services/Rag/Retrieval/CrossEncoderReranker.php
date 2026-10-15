<?php

namespace App\Services\Rag\Retrieval;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * A cross-encoder (MiniLM, in the "reranker" container) rereads the question and each passage
 * together, which ranks far better than comparing two separately made vectors.
 * Measure it on your own documents: it was trained on prose and can under-rate table chunks.
 */
class CrossEncoderReranker
{
    /**
     * @param  array<int, array{payload?: array}>  $hits
     * @return array<int, array> the same hits, best first, each with "rerank_score"
     */
    public function rerank(string $question, array $hits): array
    {
        if (!$hits) {
            return [];
        }

        $texts = array_map(fn (array $hit) => $this->passage($hit), $hits);

        $response = Http::baseUrl(rtrim((string) config('rag.retrieval.reranker_url'), '/'))
            ->acceptJson()
            ->timeout(60)
            ->post('/rerank', ['query' => $question, 'texts' => $texts, 'raw_scores' => true, 'truncate' => true]);

        if ($response->failed()) {
            throw new RuntimeException(sprintf('Reranker failed (HTTP %d): %s', $response->status(), (string) ($response->json('error') ?? 'unknown error')));
        }

        $results = $response->json();

        // A short or malformed reply must fail loudly, not look like "nothing found".
        if (!is_array($results) || count($results) !== count($hits)) {
            throw new RuntimeException('Reranker returned '.(is_array($results) ? count($results) : 'no').' scores for '.count($hits).' passages.');
        }

        $ranked = [];
        foreach ($results as $result) {
            $index = $result['index'] ?? null;
            if (!isset($hits[$index]) || !is_numeric($result['score'] ?? null)) {
                throw new RuntimeException('Reranker returned an unknown passage index or no score.');
            }
            $ranked[] = [...$hits[$index], 'rerank_score' => (float) $result['score']];
        }

        usort($ranked, fn (array $a, array $b) => $b['rerank_score'] <=> $a['rerank_score']);

        return $ranked;
    }

    /** The heading path tells the cross-encoder where the passage sits, as it does for the embedder. */
    private function passage(array $hit): string
    {
        $payload = $hit['payload'] ?? [];
        $path = (string) ($payload['heading_path'] ?? '');
        $text = (string) ($payload['text'] ?? '');

        return $path !== '' ? $path."\n".$text : $text;
    }
}
