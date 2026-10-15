<?php

namespace App\Services\Rag\Indexing;

/** A piece of a section: running prose, one table, or a vision description of a page's chart. */
final readonly class Element
{
    public const TEXT = 'text';
    public const TABLE = 'table';
    public const VISUAL = 'visual';

    public function __construct(
        public string $type,
        public string $text,
        public int $page,
    ) {}
}
