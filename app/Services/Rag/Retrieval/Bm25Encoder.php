<?php

namespace App\Services\Rag\Retrieval;

/**
 * BM25 as a sparse vector. Each word becomes a dimension (a hash of the word) whose value is
 * BM25's term-frequency part; Qdrant's "idf" modifier supplies the inverse document frequency
 * from the collection at query time. Exact words, so "SAR" or "FECA" match even when the
 * embedding model has never seen them.
 */
class Bm25Encoder
{
    private const K1 = 1.2;
    private const B = 0.75;
    // Typical chunk length in words; normalizes long chunks against short ones.
    private const AVERAGE_LENGTH = 180;

    private const STOP_WORDS = [
        'a', 'an', 'and', 'are', 'as', 'at', 'be', 'by', 'did', 'do', 'does', 'for', 'from', 'had', 'has',
        'have', 'how', 'in', 'into', 'is', 'it', 'its', 'of', 'on', 'or', 'that', 'the', 'their', 'this',
        'to', 'was', 'were', 'what', 'when', 'which', 'who', 'why', 'with',
    ];

    /** @return array{indices: array<int, int>, values: array<int, float>} */
    public function encodeDocument(string $text): array
    {
        $tokens = $this->tokens($text);
        $length = count($tokens);
        $counts = array_count_values($tokens);
        $weights = [];

        foreach ($counts as $token => $tf) {
            $norm = 1 - self::B + self::B * $length / self::AVERAGE_LENGTH;
            $this->add($weights, (string) $token, $tf * (self::K1 + 1) / ($tf + self::K1 * $norm));
        }

        return $this->vector($weights);
    }

    /** @return array{indices: array<int, int>, values: array<int, float>} */
    public function encodeQuery(string $question): array
    {
        $weights = [];

        foreach (array_unique($this->tokens($question)) as $token) {
            $this->add($weights, $token, 1.0);
        }

        return $this->vector($weights);
    }

    /** @return array<int, string> lower-case words and numbers ("6.3", "2026", "covid-19"), stop words removed */
    public function tokens(string $text): array
    {
        $text = mb_strtolower(str_replace(['’', '‘'], "'", $text));
        preg_match_all("/[\\p{L}\\p{N}]+(?:[.,'\\-][\\p{L}\\p{N}]+)*/u", $text, $m);

        $tokens = array_map(fn (string $t) => preg_replace("/'s$/", '', str_replace(',', '', $t)), $m[0]);

        return array_values(array_filter($tokens, fn (string $t) => !in_array($t, self::STOP_WORDS, true)));
    }

    private function add(array &$weights, string $token, float $weight): void
    {
        // crc32 spreads words over 2^32 dimensions; collisions between real words are rare.
        $index = crc32($token);
        $weights[$index] = ($weights[$index] ?? 0) + $weight;
    }

    private function vector(array $weights): array
    {
        return [
            'indices' => array_map('intval', array_keys($weights)),
            'values' => array_map(fn (float $v) => round($v, 6), array_values($weights)),
        ];
    }
}
