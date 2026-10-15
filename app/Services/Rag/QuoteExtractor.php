<?php

namespace App\Services\Rag;

/**
 * Turns a model answer into exact sentences (or whole tables) copied from the
 * retrieved chunks, so the user only ever sees the document's own wording.
 */
class QuoteExtractor
{
    // A "sentence" longer than this is usually a flattened table; keep only the quoted fragment.
    private const MAX_QUOTE_CHARS = 500;

    // Larger tables are quoted as the matched fragment instead of in full.
    private const MAX_TABLE_CHARS = 3000;

    // Share of the answer's words that must appear in a sentence for a paraphrase to match it.
    private const MIN_OVERLAP = 0.6;

    public function __construct(private TableParser $tables = new TableParser()) {}

    /**
     * @param  array<int, array{document: string, chunk: int|null, text: string}>  $chunks
     * @return array<int, array{document: string, chunk: int|null, text: string, table: array|null, table_header: bool}>
     */
    public function ground(string $answer, array $chunks): array
    {
        $quotes = [];

        foreach ($this->candidates($answer) as $candidate) {
            $quote = $this->exactMatch($candidate, $chunks) ?? $this->closestSentence($candidate, $chunks);

            if ($quote !== null && !isset($quotes[$quote['text']])) {
                $quotes[$quote['text']] = $quote;
            }
        }

        return array_values($quotes);
    }

    /** Quoted passages in the answer, or the whole answer when it has none. */
    private function candidates(string $answer): array
    {
        $answer = trim(preg_replace('/\[Source \d+[^\]]*\]/', '', $answer));

        preg_match_all('/["“]([^"“”]{10,})["”]/u', $answer, $matches);

        // Nothing word-like left (e.g. an answer that was only a source tag) cannot be grounded.
        return array_values(array_filter(
            $matches[1] ?: [$answer],
            fn (string $candidate) => preg_match_all('/\w+/u', $candidate) >= 2
        ));
    }

    private function exactMatch(string $candidate, array $chunks): ?array
    {
        $pattern = $this->flexiblePattern($candidate);

        foreach ($chunks as $chunk) {
            if (!preg_match($pattern, $chunk['text'], $m, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            return $this->buildQuote($chunk, $m[0][1], $m[0][1] + strlen($m[0][0]));
        }

        return null;
    }

    /** The whole table around a match, or else the full sentence(s) containing it. */
    private function buildQuote(array $chunk, int $start, int $end): array
    {
        $text = $chunk['text'];
        $region = $this->tables->regionAround($text, $start);

        if ($region !== null && $region[1] - $region[0] <= self::MAX_TABLE_CHARS) {
            $rows = $this->tables->parse(substr($text, $region[0], $region[1] - $region[0]));

            // A chunk boundary can cut a table; never present a data row as the column header.
            $hasHeader = $this->tables->isHeaderRow($rows[0]);
            $markdownRows = $hasHeader ? $rows : [array_fill(0, count($rows[0]), ''), ...$rows];

            return $this->quote($chunk, $this->tables->toMarkdown($markdownRows), $rows, $hasHeader);
        }

        $sentence = $this->withoutGarbledLead($this->singleSpaced($this->enclosingSentences($text, $start, $end)));

        return $this->quote($chunk, mb_strlen($sentence) <= self::MAX_QUOTE_CHARS
            ? $sentence
            : $this->singleSpaced(substr($text, $start, $end - $start)));
    }

    /**
     * Decorative letter-spaced headings ("F Y 2 0 1 8 F i n a n c ia l R es ults") extract as
     * fragments glued to the next sentence; start the quote at the sentence's first real words.
     */
    private function withoutGarbledLead(string $sentence): string
    {
        $tokens = explode(' ', $sentence, 5);
        $isFragmented = count($tokens) === 5
            && count(array_filter(array_slice($tokens, 0, 4), fn (string $t) => mb_strlen($t) <= 2)) === 4;

        if ($isFragmented && preg_match('/\b\p{Lu}\p{Ll}{2,} \p{Ll}{3,}/u', $sentence, $m, PREG_OFFSET_CAPTURE)) {
            return substr($sentence, $m[0][1]);
        }

        return $sentence;
    }

    private function singleSpaced(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', $text));
    }

    /** Regex matching the candidate while tolerating whitespace and quote-style differences. */
    private function flexiblePattern(string $candidate): string
    {
        $candidate = trim($candidate, " \t\n.,;:");
        // "$52,624" and "$  52,624" (a table column) are the same figure.
        $candidate = preg_replace('/([$€£])\s+/u', '$1', $candidate);
        $parts = preg_split('/\s+/u', $candidate, -1, PREG_SPLIT_NO_EMPTY);

        $escaped = array_map(
            fn (string $word) => preg_replace(
                ["/['‘’]/u", '/(\\\\\$|€|£)/u'],
                ["['‘’]", '$1\s*'],
                preg_quote($word, '/')
            ),
            $parts
        );

        // Word boundaries so "$52" never matches inside "$52,624".
        return '/(?<![\w,.])'.implode('\s+', $escaped).'(?![\w,])/u';
    }

    private function closestSentence(string $candidate, array $chunks): ?array
    {
        $wanted = $this->tokens($candidate);
        $numbers = array_filter($wanted, fn (string $t) => (bool) preg_match('/\d/', $t));

        if (!$wanted) {
            return null;
        }

        $best = null;
        $bestScore = self::MIN_OVERLAP - PHP_FLOAT_EPSILON;

        foreach ($chunks as $chunk) {
            foreach ($this->sentences($chunk['text']) as [$sentence, $offset]) {
                if (mb_strlen($sentence) > self::MAX_QUOTE_CHARS) {
                    continue;
                }

                $have = $this->tokens($sentence);

                // Every figure in the answer must appear in the source sentence.
                if (array_diff($numbers, $have)) {
                    continue;
                }

                $score = count(array_intersect($wanted, $have)) / count($wanted);
                // Strictly better only: on ties, keep the higher-ranked chunk.
                if ($score > $bestScore) {
                    $best = $this->buildQuote($chunk, $offset, $offset + strlen($sentence));
                    $bestScore = $score;
                }
            }
        }

        return $best;
    }

    private function enclosingSentences(string $text, int $start, int $end): string
    {
        $from = $start;
        $to = $end;

        foreach ($this->sentences($text) as [$sentence, $offset]) {
            $sentenceEnd = $offset + strlen($sentence);

            if ($sentenceEnd > $start && $offset < $end) {
                $from = min($from, $offset);
                $to = max($to, $sentenceEnd);
            }
        }

        return trim(substr($text, $from, $to - $from));
    }

    /** @return array<int, array{0: string, 1: int}> sentence and its byte offset */
    private function sentences(string $text): array
    {
        // Split after . ! ? (but not initials such as "U.S.") and at blank lines, which end headings.
        return preg_split(
            '/(?<=[.!?])(?<!\b[A-Z]\.)\s+(?=[A-Z0-9"“$(])|\n[ \t]*\n\s*/u',
            $text,
            -1,
            PREG_SPLIT_OFFSET_CAPTURE | PREG_SPLIT_NO_EMPTY
        ) ?: []; // false on invalid UTF-8
    }

    private function tokens(string $text): array
    {
        preg_match_all('/[a-z0-9]+(?:[.,]\d+)*/', strtolower($text), $m);

        return array_values(array_unique($m[0]));
    }

    private function quote(array $chunk, string $text, ?array $table = null, bool $tableHeader = false): array
    {
        return [
            'document' => $chunk['document'],
            'chunk' => $chunk['chunk'],
            'text' => $text,
            'table' => $table,
            'table_header' => $tableHeader,
        ];
    }
}
