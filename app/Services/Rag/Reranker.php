<?php

namespace App\Services\Rag;

/**
 * Hybrid re-ranking: vector similarity plus keyword/phrase overlap with the question.
 * Embeddings are weak at exact titles ("Summary of Net Income"); keywords are not.
 */
class Reranker
{
    private const STOP_WORDS = [
        'a', 'an', 'and', 'are', 'at', 'by', 'did', 'do', 'does', 'for', 'from', 'had', 'has', 'have',
        'how', 'in', 'is', 'it', 'many', 'me', 'much', 'of', 'on', 'or', 'show', 'tell', 'the', 'to', 'was', 'were', 'what', 'when',
        'which', 'who', 'why', 'with',
    ];

    /**
     * @param  array<int, array{score?: float, payload?: array}>  $results  vector hits, best first
     * @return array<int, array> the same hits, best first by combined score
     */
    public function rerank(string $question, array $results): array
    {
        $weight = (float) config('rag.retrieval.lexical_weight');
        $words = $this->words($question);

        if ($weight <= 0 || !$words) {
            return $results;
        }

        $scored = array_map(fn (array $hit, int $rank) => [
            'hit' => $hit,
            'rank' => $rank,
            'score' => (float) ($hit['score'] ?? 0)
                + $weight * $this->lexicalScore($words, (string) ($hit['payload']['text'] ?? '')),
        ], $results, array_keys($results));

        // Stable: equal scores keep the vector order.
        usort($scored, fn (array $a, array $b) => [$b['score'], $a['rank']] <=> [$a['score'], $b['rank']]);

        return array_column($scored, 'hit');
    }

    /** 0..1: half for content-word coverage, half for the longest phrase from the question. */
    private function lexicalScore(array $questionWords, string $text): float
    {
        $textWords = $this->words($text);
        $textSet = array_flip($textWords);

        $content = array_values(array_diff($questionWords, self::STOP_WORDS));
        $coverage = $content
            ? count(array_filter($content, fn (string $w) => isset($textSet[$w]))) / count($content)
            : 0.0;

        return 0.5 * $coverage + 0.5 * $this->longestPhrase($questionWords, ' '.implode(' ', $textWords).' ')
            / count($questionWords);
    }

    /** Length in words of the longest run of question words (2+) that appears verbatim in the text. */
    private function longestPhrase(array $questionWords, string $paddedText): int
    {
        $longest = 0;
        $count = count($questionWords);

        for ($start = 0; $start < $count; $start++) {
            for ($length = $count - $start; $length > max(1, $longest); $length--) {
                $phrase = array_slice($questionWords, $start, $length);

                if (array_diff($phrase, self::STOP_WORDS) && str_contains($paddedText, ' '.implode(' ', $phrase).' ')) {
                    $longest = $length;
                    break;
                }
            }
        }

        return $longest;
    }

    private function words(string $text): array
    {
        $text = mb_strtolower(str_replace(['’', '‘'], "'", $text));
        preg_match_all("/[\\p{L}\\p{N}]+(?:['.,][\\p{L}\\p{N}]+)*/u", $text, $m);

        return $m[0];
    }
}
