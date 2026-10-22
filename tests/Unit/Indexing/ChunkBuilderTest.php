<?php

namespace Tests\Unit\Indexing;

use App\Services\Rag\Indexing\Chunk;
use App\Services\Rag\Indexing\ChunkBuilder;
use App\Services\Rag\Indexing\Element;
use App\Services\Rag\Indexing\Section;
use Tests\TestCase;

class ChunkBuilderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'rag.chunking.parent_size' => 400,
            'rag.chunking.child_size' => 200,
            'rag.chunking.overlap_sentences' => 1,
        ]);
    }

    /** A sentence of exactly 99 characters (100 with its joining space). */
    private function sentence(int $n): string
    {
        $start = "Sentence {$n} says";

        return $start.str_repeat(' x', (int) ((98 - strlen($start)) / 2)).str_repeat('y', (98 - strlen($start)) % 2).'.';
    }

    private function section(array $elements, array $path = ['Intro'], ?string $chapter = null, ?string $region = null): Section
    {
        return new Section($path, $chapter, $region, $elements);
    }

    public function test_parents_hold_whole_sentences_up_to_the_parent_size(): void
    {
        $sentences = array_map(fn (int $n) => $this->sentence($n), range(1, 6));
        $this->assertSame(99, mb_strlen($sentences[0]));

        $chunks = (new ChunkBuilder())->build([$this->section([new Element(Element::TEXT, implode(' ', $sentences), 1)])]);

        $parents = array_values(array_unique(array_map(fn (Chunk $c) => $c->parentText, $chunks)));
        $this->assertSame([
            implode(' ', array_slice($sentences, 0, 4)),
            implode(' ', array_slice($sentences, 4, 2)),
        ], $parents);
        $this->assertSame([0, 0, 0, 1], array_map(fn (Chunk $c) => $c->parentIndex, $chunks));
    }

    public function test_children_overlap_by_the_configured_number_of_sentences(): void
    {
        $s = array_map(fn (int $n) => $this->sentence($n), range(1, 4));

        $chunks = (new ChunkBuilder())->build([$this->section([new Element(Element::TEXT, implode(' ', $s), 1)])]);

        $this->assertSame(["$s[0] $s[1]", "$s[1] $s[2]", "$s[2] $s[3]"], array_map(fn (Chunk $c) => $c->text, $chunks));
        $this->assertSame([0, 1, 2], array_map(fn (Chunk $c) => $c->index, $chunks));
        foreach ($chunks as $chunk) {
            $this->assertLessThanOrEqual(200, mb_strlen($chunk->text));
        }
    }

    public function test_children_without_overlap_do_not_repeat_sentences(): void
    {
        config(['rag.chunking.overlap_sentences' => 0]);
        $s = array_map(fn (int $n) => $this->sentence($n), range(1, 4));

        $chunks = (new ChunkBuilder())->build([$this->section([new Element(Element::TEXT, implode(' ', $s), 1)])]);

        $this->assertSame(["$s[0] $s[1]", "$s[2] $s[3]"], array_map(fn (Chunk $c) => $c->text, $chunks));
    }

    public function test_parents_never_cross_sections(): void
    {
        $chunks = (new ChunkBuilder())->build([
            $this->section([new Element(Element::TEXT, 'Short one.', 1)], ['A']),
            $this->section([new Element(Element::TEXT, 'Short two.', 2)], ['B']),
        ]);

        $this->assertCount(2, $chunks);
        $this->assertSame(['Short one.', 'Short two.'], array_map(fn (Chunk $c) => $c->parentText, $chunks));
        $this->assertSame([0, 1], array_map(fn (Chunk $c) => $c->parentIndex, $chunks));
    }

    public function test_a_table_and_a_visual_are_one_chunk_each_with_child_equal_to_parent(): void
    {
        $table = "Table: Revenue\n| Item | 2025 |\n| --- | --- |\n| Taxes | 120 |";
        $visual = 'Visual content on page 3: A bar chart.';

        $chunks = (new ChunkBuilder())->build([$this->section([
            new Element(Element::TEXT, 'Before the table.', 2),
            new Element(Element::TABLE, $table, 2),
            new Element(Element::VISUAL, $visual, 3),
            new Element(Element::TEXT, 'After the chart.', 3),
        ])]);

        $this->assertSame(
            [Element::TEXT, Element::TABLE, Element::VISUAL, Element::TEXT],
            array_map(fn (Chunk $c) => $c->type, $chunks)
        );
        $this->assertSame($table, $chunks[1]->text);
        $this->assertSame($table, $chunks[1]->parentText);
        $this->assertSame($visual, $chunks[2]->text);
        $this->assertSame($visual, $chunks[2]->parentText);
        $this->assertSame([0, 1, 2, 3], array_map(fn (Chunk $c) => $c->parentIndex, $chunks));
        $this->assertSame([2, 2, 3, 3], array_map(fn (Chunk $c) => $c->page, $chunks));
    }

    public function test_a_long_table_is_split_between_rows_repeating_its_header(): void
    {
        $header = ['Table: Revenue by year', '| Item | 2024 | 2025 |', '| --- | --- | --- |'];
        $rows = array_map(fn (int $n) => sprintf('| Revenue line %02d | %d | %d |', $n, 100 + $n, 200 + $n), range(1, 30));
        $table = implode("\n", [...$header, ...$rows]);
        $this->assertGreaterThan(600, mb_strlen($table));

        $chunks = (new ChunkBuilder())->build([$this->section([new Element(Element::TABLE, $table, 4)])]);

        $this->assertGreaterThan(1, count($chunks));
        $seenRows = [];
        foreach ($chunks as $chunk) {
            $lines = explode("\n", $chunk->text);
            $this->assertSame($header, array_slice($lines, 0, 3));
            $this->assertSame($header, array_slice(explode("\n", $chunk->parentText), 0, 3));
            $this->assertLessThanOrEqual(400, mb_strlen($chunk->parentText));
            $this->assertStringContainsString(implode("\n", array_slice($lines, 3)), $chunk->parentText);
            $seenRows = [...$seenRows, ...array_slice($lines, 3)];
        }
        $this->assertSame($rows, $seenRows);
    }

    public function test_table_children_are_row_windows_that_keep_their_column_headers(): void
    {
        $header = ['Table: Forecasts', '|  | 2025 | 2026 |', '| --- | --- | --- |'];
        $calendar = array_map(fn (int $n) => sprintf('| Calendar country %02d | %d.1 | %d.2 |', $n, $n, $n), range(1, 6));
        $fiscal = "|  | 2025/26 | 2026/27 |\n| --- | --- | --- |";
        $fiscalRows = ['| Pakistan 1 | 3.0 | 3.2 |', '| Egypt, Arab Rep. | 4.6 | 4.0 |'];
        $moreFiscal = array_map(fn (int $n) => sprintf('| Fiscal country %02d | %d.1 | %d.2 |', $n, $n, $n), range(1, 3));
        $table = implode("\n", [...$header, ...$calendar, $fiscal, ...$fiscalRows, ...$moreFiscal]);
        $this->assertLessThan(600, mb_strlen($table));

        $chunks = (new ChunkBuilder())->build([$this->section([new Element(Element::TABLE, $table, 4)])]);

        $this->assertGreaterThan(1, count($chunks));
        foreach ($chunks as $chunk) {
            $this->assertSame($header, array_slice(explode("\n", $chunk->text), 0, 3));
            $this->assertLessThanOrEqual(200, mb_strlen($chunk->text));
            // The model reads the whole table; only search sees the window.
            $this->assertSame($table, $chunk->parentText);
            $this->assertSame(0, $chunk->parentIndex);
        }

        // Rows below a sub-header are searched with that sub-header's periods, in every window.
        $fiscalWindows = array_values(array_filter($chunks, fn (Chunk $c) => !str_contains($c->text, 'Calendar country')));
        $this->assertCount(2, $fiscalWindows);
        $seenRows = [];
        foreach ($fiscalWindows as $chunk) {
            $lines = explode("\n", $chunk->text);
            $this->assertSame(explode("\n", $fiscal), array_slice($lines, 3, 2));
            $seenRows = [...$seenRows, ...array_slice($lines, 5)];
        }
        $this->assertSame([...$fiscalRows, ...$moreFiscal], $seenRows);
    }

    public function test_a_long_table_cut_into_parents_repeats_the_period_sub_header(): void
    {
        $header = ['Table: Forecasts', '|  | 2025 | 2026 |', '| --- | --- | --- |'];
        $fiscal = ['|  | 2025/26 | 2026/27 |', '| --- | --- | --- |'];
        $rows = array_map(fn (int $n) => sprintf('| Fiscal country %02d | %d.1 | %d.2 |', $n, $n, $n), range(1, 30));
        $table = implode("\n", [...$header, ...$fiscal, ...$rows]);

        $chunks = (new ChunkBuilder())->build([$this->section([new Element(Element::TABLE, $table, 4)])]);

        $parents = array_values(array_unique(array_map(fn (Chunk $c) => $c->parentText, $chunks)));
        $this->assertGreaterThan(1, count($parents));
        foreach ($parents as $parent) {
            $this->assertSame([...$header, ...$fiscal], array_slice(explode("\n", $parent), 0, 5));
        }
    }

    public function test_cited_endnotes_are_appended_to_the_chunk(): void
    {
        $chunks = (new ChunkBuilder())->build(
            [$this->section([new Element(Element::TEXT, 'Emissions fell.[^7] Targets were met.[^2]', 1)])],
            [2 => 'Company data.', 7 => 'Measured under ISO 14040.', 9 => 'Not cited.']
        );

        $expected = "Emissions fell.[^7] Targets were met.[^2]\n\nNotes:\n[^7] Measured under ISO 14040.\n[^2] Company data.";
        $this->assertSame($expected, $chunks[0]->text);
        $this->assertSame($expected, $chunks[0]->parentText);
    }

    public function test_a_citation_without_a_known_note_adds_nothing(): void
    {
        $chunks = (new ChunkBuilder())->build([$this->section([new Element(Element::TEXT, 'Emissions fell.[^4]', 1)])], [1 => 'Other.']);

        $this->assertSame('Emissions fell.[^4]', $chunks[0]->text);
    }

    public function test_metadata_is_carried_and_embedding_text_prefixes_the_heading_path(): void
    {
        $chunks = (new ChunkBuilder())->build([
            $this->section([new Element(Element::TEXT, 'Growth slowed.', 5)], ['Chapter 2', 'South Asia'], 'Chapter 2', 'South Asia'),
        ]);

        $chunk = $chunks[0];
        $this->assertSame('Chapter 2 > South Asia', $chunk->headingPath);
        $this->assertSame('Chapter 2', $chunk->chapter);
        $this->assertSame('South Asia', $chunk->region);
        $this->assertSame(5, $chunk->page);
        $this->assertSame(0, $chunk->parentIndex);
        $this->assertSame(Element::TEXT, $chunk->type);
        $this->assertSame("Chapter 2 > South Asia\nGrowth slowed.", $chunk->embeddingText());
    }

    public function test_embedding_text_without_a_heading_path_is_just_the_text(): void
    {
        $chunks = (new ChunkBuilder())->build([$this->section([new Element(Element::TEXT, 'Growth slowed.', 1)], [])]);

        $this->assertSame('Growth slowed.', $chunks[0]->embeddingText());
    }

    public function test_a_sentence_longer_than_a_child_is_cut_at_word_boundaries(): void
    {
        $long = trim(str_repeat('word ', 100));

        $chunks = (new ChunkBuilder())->build([$this->section([new Element(Element::TEXT, $long, 1)])]);

        foreach ($chunks as $chunk) {
            $this->assertLessThanOrEqual(200, mb_strlen($chunk->text));
            $this->assertMatchesRegularExpression('/^word( word)*$/', $chunk->text);
        }
    }

    public function test_no_sections_give_no_chunks(): void
    {
        $this->assertSame([], (new ChunkBuilder())->build([]));
    }
}
