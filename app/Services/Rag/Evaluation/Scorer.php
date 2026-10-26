<?php

namespace App\Services\Rag\Evaluation;

use App\Services\Rag\RagService;

/** Retrieval rank and answer correctness for one question. */
class Scorer
{
    /**
     * 1-based rank of the first hit from the set's document that contains the evidence,
     * or null when none of the ranked hits does.
     */
    public function rank(array $hits, string $document, EvalQuestion $question): ?int
    {
        foreach (array_values($hits) as $i => $hit) {
            $payload = $hit['payload'] ?? [];

            if (($payload['document_name'] ?? null) !== $document) {
                continue;
            }

            $text = $this->normalize((string) ($payload['text'] ?? ''));
            foreach ($question->evidence as $phrase) {
                if (str_contains($text, $this->normalize($phrase))) {
                    return $i + 1;
                }
            }
        }

        return null;
    }

    public function reciprocalRank(?int $rank): float
    {
        return $rank === null ? 0.0 : 1 / $rank;
    }

    /** In scope: the answer states every expected fact. Out of scope: the answer is a refusal. */
    public function isCorrect(string $answer, EvalQuestion $question): bool
    {
        if ($question->outOfScope) {
            return RagService::isRefusal($answer);
        }

        $normalized = $this->normalize($answer);

        foreach ($question->expect as $fact) {
            $fact = $this->normalize($fact);
            // A figure must stand alone: "4" must not match inside "4.4" or "2024", but "4.0" is 4.
            $pattern = preg_match('/^[\d.]+$/', $fact)
                ? '/(?<![\d.])'.preg_quote($fact, '/').(str_contains($fact, '.') ? '' : '(?:\.0+)?').'(?![\d]|\.\d)/u'
                : '/'.preg_quote($fact, '/').'/u';

            if (!preg_match($pattern, $normalized)) {
                return false;
            }
        }

        return true;
    }

    /** Case, spacing, thousands separators, footnote markers and Markdown emphasis do not count. */
    public function normalize(string $text): string
    {
        $text = preg_replace(['/\[\^\d+\]/', '/[*_`]/', '/(?<=\d),(?=\d{3}\b)/', '/[\h\v]+/u'], ['', '', '', ' '], $text);

        return trim(mb_strtolower(str_replace(['‑', '–', '—'], '-', $text)));
    }
}
