<?php

namespace Tests\Unit\Indexing;

use App\Services\Rag\Indexing\FootnoteLinker;
use App\Services\Rag\Indexing\Heading;
use App\Services\Rag\Indexing\TextRun;
use PHPUnit\Framework\TestCase;

class FootnoteLinkerTest extends TestCase
{
    private function body(int $page, float $top, float $left, float $width, string $text): TextRun
    {
        return new TextRun($page, $top, $left, $width, 12, 10, 'Times', $text);
    }

    private function small(int $page, float $top, float $left, string $text): TextRun
    {
        return new TextRun($page, $top, $left, 4, 7, 6, 'Times', $text);
    }

    public function test_a_small_raised_run_after_text_is_a_marker(): void
    {
        $pages = [1 => [
            $this->body(1, 100, 50, 80, 'recycled content'),
            $this->small(1, 98, 130, '1'),
        ]];

        $this->assertSame([1 => [1]], (new FootnoteLinker())->markers($pages));
    }

    public function test_a_subscript_is_not_a_marker(): void
    {
        $pages = [1 => [
            $this->body(1, 200, 50, 14, 'CO'),
            $this->small(1, 206, 64, '2'),
        ]];

        $this->assertSame([], (new FootnoteLinker())->markers($pages));
    }

    public function test_a_marker_far_from_the_text_is_not_a_marker(): void
    {
        $pages = [1 => [
            $this->body(1, 100, 50, 80, 'recycled content'),
            $this->small(1, 98, 300, '1'),
        ]];

        $this->assertSame([], (new FootnoteLinker())->markers($pages));
    }

    public function test_a_marker_range_and_list_give_each_number(): void
    {
        $pages = [2 => [
            $this->body(2, 100, 50, 80, 'audits'),
            new TextRun(2, 98, 130, 10, 7, 6, 'Times', '3–5'),
            $this->body(2, 140, 50, 80, 'and reports'),
            new TextRun(2, 138, 130, 10, 7, 6, 'Times', '7, 9'),
        ]];

        $this->assertSame([2 => [3, 4, 5, 7, 9]], (new FootnoteLinker())->markers($pages));
    }

    public function test_annotate_marks_numbers_that_hug_a_word_or_its_punctuation(): void
    {
        $linker = new FootnoteLinker();

        $this->assertSame('recycled content[^1] rose', $linker->annotate('recycled content1 rose', [1]));
        $this->assertSame('third-party audits.[^12] Next', $linker->annotate('third-party audits.12 Next', [12]));
    }

    public function test_annotate_leaves_decimals_standards_and_formulas_alone(): void
    {
        $text = 'Growth was 1.6 percent under ISO 14040 with CO2e reported.';

        $this->assertSame($text, (new FootnoteLinker())->annotate($text, [6, 14040, 2, 1]));
    }

    public function test_annotate_without_numbers_returns_text_unchanged(): void
    {
        $this->assertSame('content1', (new FootnoteLinker())->annotate('content1', []));
    }

    public function test_cited_lists_unique_note_numbers(): void
    {
        $this->assertSame([7, 2], (new FootnoteLinker())->cited('a[^7] b[^2] c[^7]'));
    }

    public function test_endnotes_are_parsed_after_the_notes_heading(): void
    {
        $pageTexts = [
            1 => "1 Introduction text that is not a note.\nMore body.",
            2 => 'Body text on the page before the notes.',
            3 => implode("\n", [
                'Endnotes',
                '1 First note text',
                '     continues here.',
                '2 Second note.',
                '5 Out of sequence line',
                '',
                '45',
                '3 Third note.',
            ]),
        ];
        $outline = [3 => [new Heading(3, 10, 'Endnotes', 1)]];

        $this->assertSame([
            1 => 'First note text continues here.',
            2 => 'Second note. 5 Out of sequence line',
            3 => 'Third note.',
        ], (new FootnoteLinker())->endnotes($pageTexts, $outline));
    }

    public function test_no_notes_heading_means_no_endnotes(): void
    {
        $this->assertSame([], (new FootnoteLinker())->endnotes([1 => "1 Something\n2 Else"], [1 => [new Heading(1, 10, 'Introduction', 1)]]));
    }

    public function test_codes_with_capitals_are_not_footnote_markers(): void
    {
        $text = (new \App\Services\Rag\Indexing\FootnoteLinker())->annotate('CO2 emissions fell in Q3 and COVID19 eased; recycled content2', [2, 3, 19]);

        $this->assertSame('CO2 emissions fell in Q3 and COVID19 eased; recycled content[^2]', $text);
    }
}
