<?php

namespace App\Services\Rag;

/**
 * pdftotext -layout prints a two-column page side by side, so each line mixes both columns
 * ("...each year, FY      Fiscal Year  Headcount"). This finds the blank gutter between the
 * columns and emits the left column, then the right one, keeping table rows within each intact.
 * Lines that cross the gutter (full-width headings, captions) stay whole and in place.
 */
class ColumnSplitter
{
    // A narrower page cannot hold two prose columns.
    private const MIN_PAGE_WIDTH = 60;
    // The gutter must lie in the middle band of the page, not between a table's label and numbers.
    private const GUTTER_BAND = [0.3, 0.7];
    private const GUTTER_WIDTH = 3;
    // Both columns need this many lines of text before a page counts as two-column.
    private const MIN_COLUMN_LINES = 5;
    // Share of text lines that must clear the gutter.
    private const MIN_CLEAR_SHARE = 0.6;
    // Share of right-column lines that read as prose; a column of figures or cells is a table.
    private const MIN_RIGHT_PROSE_SHARE = 0.5;
    // Share of left-column lines that run close to the gutter, as wrapped prose does; short
    // labels far from it ("ASC      Accounting Standards ...") make a list, not a column.
    private const MIN_LEFT_FILL_SHARE = 0.4;
    private const LEFT_FILL_RATIO = 0.75;

    public function split(string $page): string
    {
        $lines = explode("\n", $page);
        $gutter = $this->findGutter($lines);

        return $gutter === null ? $page : implode("\n", $this->splitAt($lines, $gutter));
    }

    /** @param array<int, string> $lines */
    private function findGutter(array $lines): ?int
    {
        $width = max(array_map('mb_strlen', $lines));

        if ($width < self::MIN_PAGE_WIDTH) {
            return null;
        }

        $best = null;
        $bestCrossings = PHP_INT_MAX;

        for ($x = (int) ($width * self::GUTTER_BAND[0]); $x <= (int) ($width * self::GUTTER_BAND[1]); $x++) {
            $crossings = count(array_filter($lines, fn (string $line) => $this->crosses($line, $x)));

            if ($crossings < $bestCrossings) {
                [$best, $bestCrossings] = [$x, $crossings];
            }
        }

        return $best !== null && $this->looksLikeTwoColumns($lines, $best) ? $best : null;
    }

    /** @param array<int, string> $lines */
    private function looksLikeTwoColumns(array $lines, int $gutter): bool
    {
        $text = array_filter($lines, fn (string $line) => trim($line) !== '');
        $clear = array_filter($text, fn (string $line) => !$this->crosses($line, $gutter));
        $left = array_filter($clear, fn (string $line) => trim($this->leftOf($line, $gutter)) !== '');
        $right = array_filter($clear, fn (string $line) => trim($this->rightOf($line, $gutter)) !== '');
        $rightProse = array_filter($right, fn (string $line) => $this->isProse($this->rightOf($line, $gutter)));
        $leftFilled = array_filter(
            $left,
            fn (string $line) => mb_strlen(rtrim($this->leftOf($line, $gutter))) >= self::LEFT_FILL_RATIO * $gutter
        );

        return count($left) >= self::MIN_COLUMN_LINES
            && count($leftFilled) >= self::MIN_LEFT_FILL_SHARE * count($left)
            && count($right) >= self::MIN_COLUMN_LINES
            && count($clear) >= self::MIN_CLEAR_SHARE * count($text)
            && count($rightProse) >= self::MIN_RIGHT_PROSE_SHARE * count($right);
    }

    /**
     * Each run of lines that clears the gutter becomes its left column followed by its right column.
     *
     * @param array<int, string> $lines
     * @return array<int, string>
     */
    private function splitAt(array $lines, int $gutter): array
    {
        $output = [];
        $region = [];

        foreach ($lines as $line) {
            if ($this->crosses($line, $gutter)) {
                $output = [...$output, ...$this->columnsOf($region, $gutter), $line];
                $region = [];
            } else {
                $region[] = $line;
            }
        }

        return [...$output, ...$this->columnsOf($region, $gutter)];
    }

    /**
     * @param array<int, string> $region
     * @return array<int, string>
     */
    private function columnsOf(array $region, int $gutter): array
    {
        $left = array_map(fn (string $line) => rtrim($this->leftOf($line, $gutter)), $region);
        $right = $this->dedent(array_map(fn (string $line) => rtrim($this->rightOf($line, $gutter)), $region));

        if (!array_filter($right, fn (string $line) => $line !== '')) {
            return $left;
        }

        return [...$this->trimBlankEdges($left), '', ...$this->trimBlankEdges($right)];
    }

    /**
     * @param array<int, string> $lines
     * @return array<int, string>
     */
    private function dedent(array $lines): array
    {
        $indents = array_map(
            fn (string $line) => mb_strlen($line) - mb_strlen(ltrim($line)),
            array_filter($lines, fn (string $line) => $line !== '')
        );
        $indent = $indents ? min($indents) : 0;

        return array_map(fn (string $line) => mb_substr($line, $indent), $lines);
    }

    /**
     * @param array<int, string> $lines
     * @return array<int, string>
     */
    private function trimBlankEdges(array $lines): array
    {
        $text = array_keys(array_filter($lines, fn (string $line) => trim($line) !== ''));

        return $text ? array_slice($lines, $text[0], end($text) - $text[0] + 1) : [];
    }

    /** Two or more words and no trailing figure: "Total net  1,234" is a table cell, not prose. */
    private function isProse(string $text): bool
    {
        return preg_match_all('/\p{L}{3,}/u', $text) >= 2 && !preg_match('/[\d%)]\s*$/u', $text);
    }

    private function crosses(string $line, int $gutter): bool
    {
        return trim(mb_substr($line, $gutter, self::GUTTER_WIDTH)) !== '';
    }

    private function leftOf(string $line, int $gutter): string
    {
        return mb_substr($line, 0, $gutter);
    }

    private function rightOf(string $line, int $gutter): string
    {
        return mb_substr($line, $gutter + self::GUTTER_WIDTH);
    }
}
