<?php

namespace App\Services\Rag\Indexing;

/** Everything under one heading, with where it lives in the document. */
final readonly class Section
{
    /**
     * @param  array<int, string>  $headingPath  outermost heading first
     * @param  array<int, Element>  $elements
     */
    public function __construct(
        public array $headingPath,
        public ?string $chapter,
        public ?string $region,
        public array $elements,
    ) {}

    public function headingPathText(): string
    {
        return implode(' > ', $this->headingPath);
    }

    public function withElement(Element $element): self
    {
        return new self($this->headingPath, $this->chapter, $this->region, [...$this->elements, $element]);
    }
}
