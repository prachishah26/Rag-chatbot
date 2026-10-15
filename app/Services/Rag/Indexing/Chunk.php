<?php

namespace App\Services\Rag\Indexing;

/**
 * A searchable child chunk. Search matches the small child; the model reads its parent.
 * The heading path is embedded in front of the text, but the stored text stays unchanged.
 */
final readonly class Chunk
{
    public function __construct(
        public int $index,
        public int $parentIndex,
        public string $type,
        public string $text,
        public string $parentText,
        public int $page,
        public string $headingPath,
        public ?string $chapter,
        public ?string $region,
    ) {}

    /** What the embedding models see: where the passage lives, then the passage. */
    public function embeddingText(): string
    {
        return $this->headingPath !== '' ? $this->headingPath."\n".$this->text : $this->text;
    }
}
