<?php

namespace App\Services\Rag;

/**
 * Detects and parses tables in layout-preserved text, where columns are
 * separated by two or more spaces (as produced by `pdftotext -layout`).
 */
class TableParser
{
    // A label line shorter than this may be the wrapped first half of a row label.
    private const MAX_LABEL_CHARS = 60;

    private const COLUMN_GAP = '/\s{2,}/u';

    // More consecutive label-like lines than this are prose (lists, headings), not wrapped labels.
    private const MAX_LABEL_RUN = 2;

    // "2025e" / "2026f" mark estimates and forecasts; "2026/27f" is a fiscal year.
    private const YEAR_CELL = '/^(?:FY\s*)?(?:19|20)\d{2}(?:\/\d{2})?[ef]?$/u';

    // A period heading rather than a year as a value: "2026f", "2026/27", "FY2026".
    private const PERIOD_MARK = '/(?:\/\d{2}|[ef]|^FY.*)$/u';

    // A figure may carry a short unit ("0.27W", "346 kg") or mark an estimate ("2025e").
    private const NUMERIC_CELL = '/^(?:[$€£]?\s*\(?[-–]?[\d][\d,]*(?:\.\d+)?%?\)?(?:\s?[a-zA-Z]{1,3}\d?[a-zA-Z]?)?|[$€£]|[—–-])$/u';

    /**
     * Byte range [start, end) of the table that contains $offset, or null when
     * the offset is not inside a table with at least two numeric rows.
     */
    public function regionAround(string $text, int $offset): ?array
    {
        $lines = $this->lines($text);
        $index = $this->lineIndexAt($lines, $offset);
        $kind = $this->kind($lines[$index][0]);

        if ($kind === 'label') {
            $next = $this->nextNonBlank($lines, $index, 1);
            if ($next === null || $this->kind($lines[$next][0]) !== 'row') {
                return null;
            }
        } elseif ($kind !== 'row') {
            return null;
        }

        $first = $this->extend($lines, $index, -1);
        $last = $this->extend($lines, $index, 1);

        // A table ends on a data row; trailing blanks or labels belong to what follows.
        while ($last > $first && $this->kind($lines[$last][0]) !== 'row') {
            $last--;
        }
        // A run of labels cut short by MAX_LABEL_RUN is prose above the table, not a title.
        while ($first < $last && $this->kind($lines[$first][0]) !== 'row'
            && !($this->kind($lines[$first][0]) === 'label' && $this->kind($lines[$first + 1][0]) === 'row')) {
            $first++;
        }

        $rows = 0;
        for ($i = $first; $i <= $last; $i++) {
            $rows += $this->kind($lines[$i][0]) === 'row' ? 1 : 0;
        }

        if ($rows < 2) {
            return null;
        }

        return [$lines[$first][1], $lines[$last][1] + strlen(rtrim($lines[$last][0]))];
    }

    /** @return array<int, array{0: int, 1: int}> byte ranges [start, end) of every table in the text, in order */
    public function regions(string $text): array
    {
        $regions = [];
        $end = -1;

        foreach ($this->lines($text) as [$line, $offset]) {
            if ($offset < $end || $this->kind($line) !== 'row') {
                continue;
            }

            $region = $this->regionAround($text, $offset);
            if ($region !== null && $region[0] >= $end) {
                $regions[] = $region;
                $end = $region[1];
            }
        }

        return $regions;
    }

    /** A column-header row labels the figures (years or text) rather than holding amounts. */
    public function isHeaderRow(array $cells): bool
    {
        foreach (array_slice($cells, 1) as $cell) {
            if ($cell !== '' && $this->isNumeric($cell) && !preg_match(self::YEAR_CELL, trim($cell))) {
                return false;
            }
        }

        return true;
    }

    /**
     * A header row naming periods ("2022/23 2023/24 ..."), e.g. a sub-header partway down a table.
     * Plain years alone ("| Founded | 1990 | 2010 |") may be data, so a period needs a mark.
     */
    public function isPeriodRow(array $cells): bool
    {
        $periods = array_filter(array_slice($cells, 1), fn (string $cell) => (bool) preg_match(self::YEAR_CELL, trim($cell))
            && (bool) preg_match(self::PERIOD_MARK, trim($cell)));

        return $periods !== [] && $this->isHeaderRow($cells);
    }

    /** @return array<int, array<int, string>> rows of cells, padded to equal width */
    public function parse(string $block): array
    {
        $rows = [];
        $pendingLabel = '';

        foreach (explode("\n", $block) as $line) {
            $cells = $this->mergeCurrencySigns($this->cells($line));

            if (!$cells) {
                continue;
            }

            if (count($cells) === 1 && !$this->isNumeric($cells[0])) {
                $pendingLabel = trim($pendingLabel.' '.$cells[0]);
                continue;
            }

            if ($pendingLabel !== '') {
                $this->isValue($cells[0])
                    ? array_unshift($cells, $pendingLabel)
                    : $cells[0] = $pendingLabel.' '.$cells[0];
                $pendingLabel = '';
            }

            $rows[] = $cells;
        }

        if ($pendingLabel !== '') {
            $rows[] = [$pendingLabel];
        }

        return $this->pad($rows);
    }

    public function toMarkdown(array $rows): string
    {
        $line = fn (array $cells) => '| '.implode(' | ', array_map(
            fn (string $cell) => str_replace('|', '\|', $cell),
            $cells
        )).' |';

        $header = array_shift($rows);
        $separator = $line(array_fill(0, count($header), '---'));
        $output = [$line($header), $separator];

        // A sub-header of periods gets its own separator, so its rows read against it and not
        // the top header: Pakistan's 3.2 belongs under "2026/27f", not under "2026f".
        foreach ($rows as $i => $row) {
            $output[] = $line($row);
            if ($i < count($rows) - 1 && $this->isPeriodRow($row)) {
                $output[] = $separator;
            }
        }

        return implode("\n", $output);
    }

    /**
     * A Markdown table as one line per row with each value named by the header above it
     * ("Pakistan 1: 2025/26e = 3.0; 2026/27f = 3.2"), so a model reading a wide table cannot
     * take a value from the wrong column. Lines outside the table are kept; a table whose first
     * row is data, not a header, is returned unchanged.
     */
    public function labelledRows(string $text): string
    {
        $lines = explode("\n", $text);
        $output = [];
        $header = null;

        foreach ($lines as $i => $line) {
            if (!str_starts_with(trim($line), '|')) {
                $output[] = $line;
                continue;
            }

            $cells = $this->markdownCells($line);

            if ($this->isSeparator($lines[$i + 1] ?? '')) {
                if (!$this->isHeaderRow($cells)) {
                    return $text;
                }
                $header = $cells;
                continue;
            }

            if ($header === null || $this->isSeparator($line)) {
                continue;
            }

            $values = [];
            foreach (array_slice($cells, 1, null, true) as $column => $cell) {
                if ($cell !== '') {
                    $name = $header[$column] ?? '';
                    $values[] = $name !== '' ? "{$name} = {$cell}" : $cell;
                }
            }
            $output[] = $cells[0] !== '' ? $cells[0].': '.implode('; ', $values) : implode('; ', $values);
        }

        return implode("\n", $output);
    }

    /** "| Pakistan 1 | 3.0 |" → ["Pakistan 1", "3.0"] */
    public function markdownCells(string $line): array
    {
        // "\|" is a pipe inside a cell (see toMarkdown), not a column border.
        $cells = preg_split('/(?<!\\\\)\|/', trim($line));

        return array_map(fn (string $cell) => str_replace('\\|', '|', trim($cell)), array_slice($cells, 1, -1));
    }

    /** "| --- | --- |" */
    public function isSeparator(string $line): bool
    {
        return (bool) preg_match('/^\|(?:\s*-+\s*\|)+$/', trim($line));
    }

    private function kind(string $line): string
    {
        $trimmed = trim($line);

        if ($trimmed === '') {
            return 'blank';
        }

        $cells = $this->cells($trimmed);
        foreach (array_slice($cells, 1) as $cell) {
            if ($this->isNumeric($cell)) {
                return 'row';
            }
        }

        // A line of fiscal years ("2023/24  2024/25") is a sub-header that continues the table;
        // one such token ("In   FY2023/24  and more") is prose.
        $periods = array_filter(array_slice($cells, 1), fn (string $cell) => (bool) preg_match(self::YEAR_CELL, trim($cell)));
        if (count($periods) >= 2) {
            return 'row';
        }

        $isLabel = count($cells) === 1
            && mb_strlen($trimmed) <= self::MAX_LABEL_CHARS
            && !preg_match('/[.!?]$/', $trimmed);

        return $isLabel ? 'label' : 'text';
    }

    /** Walks from $index in $direction over table lines; stops at prose or a double blank. */
    private function extend(array $lines, int $index, int $direction): int
    {
        $edge = $index;
        $blanks = 0;
        $labels = 0;

        for ($i = $index + $direction; isset($lines[$i]); $i += $direction) {
            $kind = $this->kind($lines[$i][0]);

            if ($kind === 'text') {
                break;
            }

            $blanks = $kind === 'blank' ? $blanks + 1 : 0;
            $labels = $kind === 'label' ? $labels + 1 : ($kind === 'row' ? 0 : $labels);
            if ($blanks > 1 || $labels > self::MAX_LABEL_RUN) {
                break;
            }

            $edge = $i;
        }

        return $edge;
    }

    private function nextNonBlank(array $lines, int $index, int $direction): ?int
    {
        for ($i = $index + $direction; isset($lines[$i]); $i += $direction) {
            if (trim($lines[$i][0]) !== '') {
                return $i;
            }
        }

        return null;
    }

    /** @return array<int, array{0: string, 1: int}> each line with its byte offset */
    private function lines(string $text): array
    {
        $lines = [];
        $offset = 0;

        foreach (explode("\n", $text) as $line) {
            $lines[] = [$line, $offset];
            $offset += strlen($line) + 1;
        }

        return $lines;
    }

    private function lineIndexAt(array $lines, int $offset): int
    {
        foreach ($lines as $i => [$line, $start]) {
            if ($offset <= $start + strlen($line)) {
                return $i;
            }
        }

        return array_key_last($lines);
    }

    private function cells(string $line): array
    {
        return preg_split(self::COLUMN_GAP, trim($line), -1, PREG_SPLIT_NO_EMPTY);
    }

    private function isNumeric(string $cell): bool
    {
        return (bool) preg_match(self::NUMERIC_CELL, trim($cell));
    }

    /** A figure or a period heading: a cell that sits in a value column, not the label column. */
    private function isValue(string $cell): bool
    {
        return $this->isNumeric($cell) || (bool) preg_match(self::YEAR_CELL, trim($cell));
    }

    /** "$" printed in its own column belongs to the amount after it. */
    private function mergeCurrencySigns(array $cells): array
    {
        $merged = [];

        for ($i = 0; $i < count($cells); $i++) {
            if (in_array($cells[$i], ['$', '€', '£'], true) && isset($cells[$i + 1])) {
                $merged[] = $cells[$i].' '.$cells[++$i];
                continue;
            }
            $merged[] = $cells[$i];
        }

        return $merged;
    }

    /** Inserts empty cells after the label so figures stay in their right-hand columns. */
    private function pad(array $rows): array
    {
        $width = max(array_map('count', $rows) ?: [0]);

        return array_map(function (array $row) use ($width) {
            $missing = $width - count($row);
            if ($missing > 0) {
                array_splice($row, $this->isValue($row[0]) ? 0 : 1, 0, array_fill(0, $missing, ''));
            }

            return $row;
        }, $rows);
    }
}
