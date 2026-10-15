<?php

namespace App\Services\Rag\Indexing;

/**
 * Picks pages whose meaning is in a chart, infographic or call-out rather than in sentences:
 * mostly short text fragments, several of them figures. Text extraction keeps a chart's labels
 * but not what they add up to.
 */
class VisualPageDetector
{
    private const MIN_SHORT_SHARE = 0.6;
    private const MIN_FIGURES = 5;
    private const MIN_RUNS = 10;

    /**
     * @param  array<int, array<int, TextRun>>  $pages
     * @return array<int, int> page numbers to describe
     */
    public function select(array $pages): array
    {
        $mode = (string) config('rag.vision.pages');

        if ($mode === 'none' || !$pages) {
            return [];
        }

        if ($mode === 'all') {
            return array_keys($pages);
        }

        if (count($pages) > (int) config('rag.vision.max_document_pages')) {
            return [];
        }

        return array_values(array_filter(array_keys($pages), fn (int $page) => $this->looksVisual($pages[$page])));
    }

    /** @param  array<int, TextRun>  $runs */
    public function looksVisual(array $runs): bool
    {
        if (count($runs) < self::MIN_RUNS) {
            return false;
        }

        $short = array_filter($runs, fn (TextRun $run) => str_word_count($run->text) <= 3);
        $figures = array_filter($runs, fn (TextRun $run) => preg_match(
            '/^[–-]?[$€£]?\d[\d,.]*\s?(%|kg|g|t|W|kWh|percent|million|billion)?$/u',
            trim($run->text)
        ));

        return count($short) / count($runs) >= self::MIN_SHORT_SHARE && count($figures) >= self::MIN_FIGURES;
    }
}
