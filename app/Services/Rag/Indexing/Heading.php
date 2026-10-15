<?php

namespace App\Services\Rag\Indexing;

final readonly class Heading
{
    public function __construct(
        public int $page,
        public float $top,
        public string $text,
        // 1 = largest heading font in the document.
        public int $level,
    ) {}

    /** "Chapter 2" when this heading opens a chapter ("CHAPTER 2", "Chapter II"), else null. */
    public function chapter(): ?string
    {
        return preg_match('/^chapter\s+(\d+|[IVXL]+)\b/i', $this->text, $m) ? self::chapterLabel($m[1]) : null;
    }

    /** "2", "II" and "ii" all become "Chapter 2", so questions and headings agree. */
    public static function chapterLabel(string $number): string
    {
        if (ctype_digit($number)) {
            return 'Chapter '.(int) $number;
        }

        $values = ['I' => 1, 'V' => 5, 'X' => 10, 'L' => 50];
        $digits = array_map(fn (string $c) => $values[$c], str_split(strtoupper($number)));
        $total = 0;

        foreach ($digits as $i => $value) {
            $total += isset($digits[$i + 1]) && $digits[$i + 1] > $value ? -$value : $value;
        }

        return 'Chapter '.$total;
    }
}
