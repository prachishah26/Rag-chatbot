<?php

namespace App\Services\Rag\Indexing;

use App\Services\Rag\TableParser;

/**
 * Turns page texts plus the font outline into sections: each heading opens a section that knows
 * its heading path, chapter and region. Tables become their own elements; footnote markers are
 * marked as "[^n]"; a page's chart description is added where the page ends.
 */
class StructureBuilder
{
    public function __construct(
        private TableParser $tables = new TableParser(),
        private FootnoteLinker $footnotes = new FootnoteLinker(),
        private RegionCatalog $regions = new RegionCatalog(),
    ) {}

    /**
     * @param  array<int, string>  $pageTexts  layout text keyed by 1-based page
     * @param  array<int, array<int, Heading>>  $outline
     * @param  array<int, array<int, int>>  $markers  footnote numbers per page
     * @param  array<int, string>  $visuals  vision descriptions per page
     * @return array<int, Section>
     */
    public function build(array $pageTexts, array $outline = [], array $markers = [], array $visuals = []): array
    {
        $state = new BuildState();

        foreach ($pageTexts as $page => $text) {
            $text = $this->footnotes->annotate($text, $markers[$page] ?? []);
            $this->readPage($state, $page, $text, $outline[$page] ?? []);

            if (isset($visuals[$page]) && trim($visuals[$page]) !== '') {
                $state->addElement(new Element(Element::VISUAL, trim($visuals[$page]), $page));
            }
        }

        return $state->finish();
    }

    /** @param  array<int, Heading>  $headings */
    private function readPage(BuildState $state, int $page, string $text, array $headings): void
    {
        $tableRanges = $this->tables->regions($text);
        $lines = $this->lines($text);
        $prose = [];
        $remaining = array_values($headings);

        // A side heading set apart from the text ("Carbon" ... "Footprint" beside a column) never
        // forms a line of its own; open the page's top heading at the start of the page instead.
        if ($remaining && !$this->appearsInLines($lines, $remaining[0])) {
            $state->openHeading($remaining[0], $this->regions->forHeading($remaining[0]->text));
            array_shift($remaining);
        }

        $flushProse = function () use (&$prose, $state, $page) {
            $paragraphs = $this->paragraphs($prose);
            if ($paragraphs !== '') {
                $state->addElement(new Element(Element::TEXT, $paragraphs, $page));
            }
            $prose = [];
        };

        for ($i = 0; $i < count($lines); $i++) {
            [$line, $offset] = $lines[$i];

            if ($range = $this->tableAt($tableRanges, $offset)) {
                $flushProse();
                $state->addElement(new Element(Element::TABLE, $this->tableText($text, $range, $state), $page));
                while (isset($lines[$i + 1]) && $lines[$i + 1][1] < $range[1]) {
                    $i++;
                }
                continue;
            }

            [$heading, $consumed] = $this->headingAt($lines, $i, $remaining);
            if ($heading !== null) {
                $flushProse();
                $remaining = array_values(array_filter($remaining, fn (Heading $h) => $h !== $heading));
                $state->openHeading($heading, $this->regions->forHeading($heading->text));
                $i += $consumed - 1;
                continue;
            }

            // "Definitions      Bio-based plastics: ..." — a heading in the margin of a text line.
            if ($heading = $this->headingStartingLine($line, $remaining)) {
                $flushProse();
                $remaining = array_values(array_filter($remaining, fn (Heading $h) => $h !== $heading));
                $state->openHeading($heading, $this->regions->forHeading($heading->text));
                $prose[] = $this->withoutLeadingWords($line, $heading->text);
                continue;
            }

            $prose[] = $line;
        }

        $flushProse();
    }

    /**
     * The heading that starts at line $i, and how many lines it spans; a heading set on two
     * lines ("Financial Position and Results for" / "Fiscal Year 2018") is matched across both.
     *
     * @return array{0: ?Heading, 1: int}
     */
    private function headingAt(array $lines, int $i, array $headings): array
    {
        $first = $this->normalize($lines[$i][0]);

        if (mb_strlen($first) < 3) {
            return [null, 0];
        }

        foreach ($headings as $heading) {
            $target = $this->normalize($heading->text);
            $seen = '';

            for ($j = $i; isset($lines[$j]) && $j < $i + 4; $j++) {
                $seen .= $this->normalize($lines[$j][0]);

                if ($seen === $target) {
                    return [$heading, $j - $i + 1];
                }
                if (!str_starts_with($target, $seen)) {
                    break;
                }
            }
        }

        return [null, 0];
    }

    private function headingStartingLine(string $line, array $headings): ?Heading
    {
        foreach ($headings as $heading) {
            $target = $this->normalize($heading->text);
            // The heading must be followed by a column gap, not run on into a sentence.
            if (mb_strlen($target) >= 5
                && preg_match('/^\s*'.$this->wordsPattern($heading->text).'\s{2,}\S/u', $line)) {
                return $heading;
            }
        }

        return null;
    }

    private function withoutLeadingWords(string $line, string $words): string
    {
        return preg_replace('/^\s*'.$this->wordsPattern($words).'\s*/u', '', $line);
    }

    private function wordsPattern(string $text): string
    {
        $words = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY);

        return implode('\s+', array_map(fn (string $w) => preg_quote($w, '/'), $words));
    }

    private function appearsInLines(array $lines, Heading $heading): bool
    {
        for ($i = 0; $i < count($lines); $i++) {
            if ($this->headingAt($lines, $i, [$heading])[0] !== null
                || $this->headingStartingLine($lines[$i][0], [$heading]) !== null) {
                return true;
            }
        }

        return false;
    }

    private function tableText(string $text, array $range, BuildState $state): string
    {
        $block = substr($text, $range[0], $range[1] - $range[0]);
        $rows = $this->tables->parse($block);

        // Every value next to its column heading, so the numbers survive chunking and embedding.
        $table = count($rows) >= 2 ? $this->tables->toMarkdown($rows) : trim($block);
        $title = $state->currentHeading();

        return $title !== null ? "Table: {$title}\n{$table}" : $table;
    }

    private function tableAt(array $ranges, int $offset): ?array
    {
        foreach ($ranges as $range) {
            if ($offset >= $range[0] && $offset < $range[1]) {
                return $range;
            }
        }

        return null;
    }

    /** Joins wrapped lines into paragraphs; blank lines separate paragraphs. */
    private function paragraphs(array $lines): string
    {
        $paragraphs = [];
        $current = [];

        foreach ($lines as $line) {
            $trimmed = preg_replace('/\s{2,}/u', ' ', trim($line));

            if ($trimmed === '') {
                if ($current) {
                    $paragraphs[] = implode(' ', $current);
                    $current = [];
                }
                continue;
            }

            $current[] = $trimmed;
        }

        if ($current) {
            $paragraphs[] = implode(' ', $current);
        }

        return implode("\n\n", $paragraphs);
    }

    /** @return array<int, array{0: string, 1: int}> */
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

    private function normalize(string $text): string
    {
        return preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower(preg_replace('/\[\^\d+\]/', '', $text)));
    }
}
