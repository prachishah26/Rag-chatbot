<?php

namespace App\Services\Rag\Indexing;

/**
 * Recovers a document's outline from fonts. Four rules:
 *  1. Body text is the font (size + face) holding the most characters; a heading is set larger.
 *  2. A heading is short and made of words, not figures.
 *  3. Text repeated on many pages is a running header or footer, not a heading.
 *  4. Figure, table and box captions and source lines are labels, not headings.
 * Heading levels follow font size: the largest heading size is level 1.
 */
class OutlineDetector
{
    public function __construct(private RunningText $runningText = new RunningText()) {}

    private const MIN_SIZE_RATIO = 1.15;
    private const MAX_HEADING_CHARS = 100;
    private const MAX_LEVELS = 4;
    private const CAPTION = '/^(figure|fig\.|table|box|chart|map|exhibit|panel|source|sources|note|notes)\b[\s\d.:A-Z]*/i';

    /**
     * @param  array<int, array<int, TextRun>>  $pages
     * @return array<int, array<int, Heading>> headings keyed by page, top to bottom
     */
    public function detect(array $pages): array
    {
        $runs = array_merge(...array_values($pages ?: [[]]));
        $body = $this->bodySize($runs);

        if ($body === null) {
            return [];
        }

        $running = $this->runningText->detect($pages);
        $candidates = [];

        foreach ($pages as $page => $pageRuns) {
            foreach ($this->mergeLines($pageRuns) as $run) {
                if ($this->isHeading($run, $body, $running)) {
                    $candidates[] = $run;
                }
            }
        }

        $levels = $this->levels($candidates);
        $outline = [];

        foreach ($candidates as $run) {
            $outline[$run->page][] = new Heading($run->page, $run->top, $run->text, $levels[$this->sizeKey($run->size)]);
        }

        return $outline;
    }

    private function isHeading(TextRun $run, float $bodySize, array $running): bool
    {
        $text = $run->text;

        // A chapter opener ("CHAPTER 1") shares its words with the running header; keep it.
        $isChapter = (new Heading($run->page, $run->top, $text, 1))->chapter() !== null;

        return $run->size >= $bodySize * self::MIN_SIZE_RATIO                          // rule 1
            && mb_strlen($text) <= self::MAX_HEADING_CHARS                              // rule 2
            && preg_match_all('/\p{L}/u', $text) >= 3
            && preg_match_all('/\p{L}/u', $text) > preg_match_all('/\d/', $text)
            && !preg_match('/^(\p{L}\s){3,}/u', $text)                                  // letter-spaced banner
            && !(preg_match('/[.!?]$/u', $text) && str_word_count($text) >= 6)       // a lead sentence
            && !preg_match('#www\.|https?:|\.(com|org|gov|net)\b|/#i', $text)          // a web address
            && ($isChapter || !$this->runningText->isRunning($text, $running))                // rule 3
            && !preg_match(self::CAPTION, $text);                                      // rule 4
    }

    /** The font size carrying the most characters. */
    private function bodySize(array $runs): ?float
    {
        $chars = [];
        foreach ($runs as $run) {
            $key = $this->sizeKey($run->size).'|'.$run->font;
            $chars[$key] = ($chars[$key] ?? 0) + mb_strlen($run->text);
        }

        if (!$chars) {
            return null;
        }

        arsort($chars);

        return (float) explode('|', (string) array_key_first($chars))[0];
    }

    /**
     * Rebuilds lines from runs (pdftohtml splits a line at every font change), then joins a
     * heading set on several lines ("MANAGEMENT DISCUSSION" / "AND ANALYSIS") into one run.
     */
    private function mergeLines(array $runs): array
    {
        $merged = [];

        foreach ($this->lines($runs) as $line) {
            $previous = null;
            for ($i = count($merged) - 1; $i >= 0 && $i >= count($merged) - 4; $i--) {
                if ($this->continuesHeading($merged[$i], $line)) {
                    $previous = $i;
                    break;
                }
            }

            if ($previous === null) {
                $merged[] = $line;
                continue;
            }

            $last = $merged[$previous];
            $merged[$previous] = new TextRun(
                $last->page, $last->top, min($last->left, $line->left),
                max($last->left + $last->width, $line->left + $line->width) - min($last->left, $line->left),
                $line->bottom() - $last->top, $last->size, $last->font, $last->text.' '.$line->text,
            );
        }

        return $merged;
    }

    private function continuesHeading(TextRun $above, TextRun $line): bool
    {
        $overlap = min($above->left + $above->width, $line->left + $line->width) - max($above->left, $line->left);

        return abs($above->size - $line->size) < 0.5
            && $line->top > $above->top
            && $line->top - $above->bottom() <= $line->height * 0.8
            && $overlap > 0
            && !preg_match('/[.!?:]$/u', $above->text);
    }

    /** Joins runs that sit on one line, skipping superscripts such as footnote markers. */
    private function lines(array $runs): array
    {
        usort($runs, fn (TextRun $a, TextRun $b) => [round($a->top), $a->left] <=> [round($b->top), $b->left]);

        $lines = [];
        foreach ($runs as $run) {
            $last = end($lines);
            $gap = $last instanceof TextRun ? $run->left - ($last->left + $last->width) : 0;
            $sameLine = $last instanceof TextRun
                && $last->sharesLineWith($run)
                && $gap >= -2
                && $gap <= max($last->size, $run->size) * 0.6;

            if ($sameLine && $run->size < $last->size * 0.8) {
                continue;
            }

            if ($sameLine && abs($run->size - $last->size) < 0.5) {
                $lines[array_key_last($lines)] = new TextRun(
                    $last->page, min($last->top, $run->top), $last->left, $run->left + $run->width - $last->left,
                    max($last->bottom(), $run->bottom()) - min($last->top, $run->top), $last->size, $last->font,
                    trim(preg_replace('/\s+/u', ' ', $last->text.' '.$run->text)),
                );
                continue;
            }

            $lines[] = $run;
        }

        return $lines;
    }

    /** @return array<string, int> level per heading font size, largest first */
    private function levels(array $candidates): array
    {
        $sizes = array_unique(array_map(fn (TextRun $run) => $this->sizeKey($run->size), $candidates));
        usort($sizes, fn (string $a, string $b) => (float) $b <=> (float) $a);

        $levels = [];
        foreach (array_values($sizes) as $i => $size) {
            $levels[$size] = min($i + 1, self::MAX_LEVELS);
        }

        return $levels;
    }

    private function sizeKey(float $size): string
    {
        return (string) round($size);
    }

}
