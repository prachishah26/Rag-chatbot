<?php

namespace App\Services\Rag\Indexing;

/**
 * Footnote markers become stray digits in extracted text ("recycled content1"), and the caveat
 * they point to sits pages away. This finds the raised markers in the layout, marks them in the
 * text as "[^1]", and reads the numbered endnotes so a chunk can carry the notes it cites.
 */
class FootnoteLinker
{
    private const MARKER = '/^\d{1,3}(?:\s*[,–-]\s*\d{1,3})*$/u';
    private const NOTES_HEADING = '/^(end|foot)?notes$/i';

    /**
     * @param  array<int, array<int, TextRun>>  $pages
     * @return array<int, array<int, int>> marker numbers found on each page
     */
    public function markers(array $pages): array
    {
        $markers = [];

        foreach ($pages as $page => $runs) {
            foreach ($runs as $run) {
                if (preg_match(self::MARKER, $run->text) && $this->isRaised($run, $runs)) {
                    foreach ($this->numbers($run->text) as $number) {
                        $markers[$page][] = $number;
                    }
                }
            }
        }

        return array_map(fn (array $numbers) => array_values(array_unique($numbers)), $markers);
    }

    /**
     * @param  array<int, string>  $pageTexts  layout text keyed by page
     * @param  array<int, array<int, Heading>>  $outline
     * @return array<int, string> endnote text keyed by its number
     */
    public function endnotes(array $pageTexts, array $outline): array
    {
        $notesPages = array_keys(array_filter($outline, fn (array $headings) => (bool) array_filter(
            $headings,
            fn (Heading $h) => preg_match(self::NOTES_HEADING, trim($h->text))
        )));

        if (!$notesPages) {
            return [];
        }

        // Notes can start on the page before their heading repeats; read from the first notes page on.
        $lines = [];
        foreach ($pageTexts as $page => $text) {
            if ($page >= min($notesPages) - 1) {
                $lines = [...$lines, ...explode("\n", $text)];
            }
        }

        return $this->parseNotes($lines);
    }

    /** "content1" → "content[^1]" for the marker numbers known to be on this page. */
    public function annotate(string $text, array $numbers): string
    {
        if (!$numbers) {
            return $text;
        }

        $alternatives = implode('|', array_map('intval', $numbers));

        // A marker hugs a lower-case word, or punctuation right after a word ("audits.12").
        // "ISO 14040", "CO2", "Q3", "COVID19" and decimals such as "1.6" are not markers.
        return preg_replace(
            '/(?:(?<=[\p{Ll})’”%®])|(?<=\p{L}[.,;:]))('.$alternatives.')(?![\d\p{L}])/u',
            '[^$1]',
            $text
        );
    }

    /** @return array<int, int> numbers cited as "[^n]" in the text */
    public function cited(string $text): array
    {
        preg_match_all('/\[\^(\d+)\]/', $text, $m);

        return array_values(array_unique(array_map('intval', $m[1])));
    }

    /** A marker is smaller than, and raised above, the text it follows on the same line. */
    private function isRaised(TextRun $marker, array $runs): bool
    {
        foreach ($runs as $run) {
            if ($run !== $marker
                && $run->sharesLineWith($marker)
                && $run->size >= $marker->size * 1.25
                // Raised: its middle sits above the middle of the text it follows (a subscript, as in CO2, sits below).
                && $marker->top + $marker->height / 2 < $run->top + $run->height / 2 - 0.5
                && abs($marker->left - ($run->left + $run->width)) <= $run->size) {
                return true;
            }
        }

        return false;
    }

    private function numbers(string $text): array
    {
        if (preg_match('/^(\d+)\s*[–-]\s*(\d+)$/u', $text, $m) && $m[2] > $m[1] && $m[2] - $m[1] < 20) {
            return range((int) $m[1], (int) $m[2]);
        }

        preg_match_all('/\d+/', $text, $m);

        return array_map('intval', $m[0]);
    }

    /** Numbered entries in sequence (1, 2, 3 ...); indented lines continue the entry above. */
    private function parseNotes(array $lines): array
    {
        $notes = [];
        $current = null;

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if (preg_match('/^(\d{1,3})\s+(\S.*)$/u', $trimmed, $m) && (int) $m[1] === ($current ?? 0) + 1) {
                $current = (int) $m[1];
                $notes[$current] = $m[2];
                continue;
            }

            // A blank line or a footer ends nothing; text before the first note is skipped.
            if ($current !== null && $trimmed !== '' && !preg_match('/^\d+$/', $trimmed)) {
                $notes[$current] .= ' '.preg_replace('/\s{2,}/u', ' ', $trimmed);
            }
        }

        return array_map('trim', $notes);
    }
}
