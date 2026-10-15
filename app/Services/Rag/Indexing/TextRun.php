<?php

namespace App\Services\Rag\Indexing;

/** One run of text on a PDF page, with its position and font (from pdftohtml -xml). */
final readonly class TextRun
{
    public function __construct(
        public int $page,
        public float $top,
        public float $left,
        public float $width,
        public float $height,
        public float $size,
        public string $font,
        public string $text,
    ) {}

    public function bottom(): float
    {
        return $this->top + $this->height;
    }

    /** True when the two runs share part of a line (their vertical spans overlap). */
    public function sharesLineWith(self $other): bool
    {
        return $this->top < $other->bottom() && $other->top < $this->bottom();
    }
}
