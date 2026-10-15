<?php

namespace App\Services\Rag\Retrieval;

/** Dense embeddings: one vector per text. */
interface Embedder
{
    /**
     * @param  array<int, string>  $texts  passages to index
     * @return array<int, array<int, float>>
     */
    public function embedDocuments(array $texts): array;

    /** @return array<int, float> */
    public function embedQuery(string $question): array;
}
