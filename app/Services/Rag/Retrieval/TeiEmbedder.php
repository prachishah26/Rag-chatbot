<?php

namespace App\Services\Rag\Retrieval;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** BAAI bge-small through Hugging Face text-embeddings-inference (the "embeddings" container). */
class TeiEmbedder implements Embedder
{
    // bge models are trained with this instruction in front of search queries, not passages.
    public const QUERY_INSTRUCTION = 'Represent this sentence for searching relevant passages: ';

    public function embedDocuments(array $texts): array
    {
        $vectors = [];

        foreach (array_chunk(array_values($texts), max(1, (int) config('rag.embeddings.batch_size'))) as $batch) {
            $vectors = [...$vectors, ...$this->embed($batch)];
        }

        return $vectors;
    }

    public function embedQuery(string $question): array
    {
        return $this->embed([self::QUERY_INSTRUCTION.$question])[0];
    }

    /** @return array<int, array<int, float>> */
    private function embed(array $inputs): array
    {
        $response = $this->client()->post('/embed', ['inputs' => $inputs, 'truncate' => true]);

        if ($response->failed()) {
            throw new RuntimeException(sprintf(
                'Embedding service failed (HTTP %d): %s',
                $response->status(),
                (string) ($response->json('error') ?? 'unknown error')
            ));
        }

        $vectors = $response->json();

        if (!is_array($vectors) || count($vectors) !== count($inputs) || !is_array($vectors[0] ?? null)) {
            throw new RuntimeException('Embedding service returned an invalid response.');
        }

        return array_map(fn (array $vector) => array_map('floatval', $vector), $vectors);
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('rag.embeddings.tei_url'), '/'))
            ->acceptJson()
            ->timeout((int) config('rag.embeddings.timeout'));
    }
}
