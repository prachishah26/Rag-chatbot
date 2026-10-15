<?php

namespace App\Services\Rag\Retrieval;

/** A passage handed to the model: a parent section (advanced) or a child chunk (naive). */
final readonly class Passage
{
    public function __construct(
        public string $document,
        public int $documentId,
        public int $chunk,
        public string $text,
        public float $score,
        public string $headingPath = '',
        public ?int $page = null,
        public string $type = 'text',
    ) {}

    public static function fromHit(array $hit, bool $useParent): self
    {
        $payload = $hit['payload'] ?? [];
        $text = $useParent ? ($payload['parent_text'] ?? $payload['text'] ?? '') : ($payload['text'] ?? '');

        return new self(
            document: (string) ($payload['document_name'] ?? 'document'),
            documentId: (int) ($payload['document_id'] ?? 0),
            chunk: (int) ($payload['chunk_index'] ?? 0),
            text: trim((string) $text),
            score: (float) ($hit['rerank_score'] ?? $hit['score'] ?? 0),
            headingPath: (string) ($payload['heading_path'] ?? ''),
            page: isset($payload['page']) ? (int) $payload['page'] : null,
            type: (string) ($payload['type'] ?? 'text'),
        );
    }
}
