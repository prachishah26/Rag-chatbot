<?php

namespace App\Services\Rag\Indexing;

/** Splits prose into whole sentences so no chunk starts or ends mid-sentence. */
class SentenceSplitter
{
    // Abbreviations whose period does not end a sentence.
    private const ABBREVIATIONS = [
        'u.s', 'e.g', 'i.e', 'etc', 'fig', 'figs', 'no', 'nos', 'vol', 'vs', 'mr', 'mrs', 'ms', 'dr', 'st',
        'inc', 'ltd', 'co', 'corp', 'jan', 'feb', 'mar', 'apr', 'jun', 'jul', 'aug', 'sep', 'sept', 'oct',
        'nov', 'dec', 'approx', 'est', 'p', 'pp', 'ch', 'sec', 'al',
    ];

    /** @return array<int, string> */
    public function split(string $text): array
    {
        $sentences = [];

        foreach (preg_split('/\n\s*\n/u', $text) as $paragraph) {
            $paragraph = trim(preg_replace('/\s+/u', ' ', $paragraph));

            if ($paragraph !== '') {
                $sentences = [...$sentences, ...$this->splitParagraph($paragraph)];
            }
        }

        return $sentences;
    }

    private function splitParagraph(string $paragraph): array
    {
        // Candidate ends: . ! ? (with closing quotes, brackets or a footnote marker) before a capital, digit or quote.
        $parts = preg_split('/(?<=[.!?])((?:[”"’)\]]|\[\^\d+\])*)\s+(?=[\p{Lu}\d“"‘(\[$€£])/u', $paragraph, -1, PREG_SPLIT_DELIM_CAPTURE);

        $sentences = [];
        $buffer = '';

        for ($i = 0; $i < count($parts); $i += 2) {
            $buffer .= $parts[$i].($parts[$i + 1] ?? '');

            if (!isset($parts[$i + 2]) || !$this->endsWithAbbreviation($buffer)) {
                $sentences[] = trim($buffer);
                $buffer = '';
                continue;
            }

            $buffer .= ' ';
        }

        if (trim($buffer) !== '') {
            $sentences[] = trim($buffer);
        }

        return $sentences;
    }

    private function endsWithAbbreviation(string $text): bool
    {
        if (!preg_match('/(?:^|[\s(])([\p{L}.]+)\.$/u', $text, $m)) {
            return false;
        }

        $word = mb_strtolower($m[1]);

        // Single initials ("John W. Crawford") and listed abbreviations.
        return mb_strlen($word) === 1 || in_array($word, self::ABBREVIATIONS, true);
    }
}
