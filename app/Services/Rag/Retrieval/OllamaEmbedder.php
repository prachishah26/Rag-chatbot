<?php

namespace App\Services\Rag\Retrieval;

use App\Services\Rag\OllamaService;

/** Embeddings from the local Ollama model (RAG_EMBEDDING_DRIVER=ollama). */
class OllamaEmbedder implements Embedder
{
    public function __construct(private OllamaService $ollama) {}

    public function embedDocuments(array $texts): array
    {
        return array_map(fn (string $text) => $this->ollama->embed($text), array_values($texts));
    }

    public function embedQuery(string $question): array
    {
        return $this->ollama->embed($question);
    }
}
