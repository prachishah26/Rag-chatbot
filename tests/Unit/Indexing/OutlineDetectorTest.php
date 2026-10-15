<?php

namespace Tests\Unit\Indexing;

use App\Services\Rag\Indexing\Heading;
use App\Services\Rag\Indexing\OutlineDetector;
use App\Services\Rag\Indexing\TextRun;
use PHPUnit\Framework\TestCase;

class OutlineDetectorTest extends TestCase
{
    private const BODY = 'The economy grew steadily over the year as trade and investment recovered';

    private function textRun(int $page, float $top, string $text, float $size, float $left = 50, string $font = 'Times', ?float $width = null): TextRun
    {
        return new TextRun($page, $top, $left, $width ?? mb_strlen($text) * $size * 0.5, $size * 1.2, $size, $font, $text);
    }

    /** Ten lines of body text at size 10, starting well below the headings. */
    private function body(int $page, float $top = 500): array
    {
        return array_map(fn (int $i) => $this->textRun($page, $top + $i * 20, self::BODY, 10), range(0, 9));
    }

    /** @return array<int, string> heading texts in document order */
    private function texts(array $outline): array
    {
        return array_map(fn (Heading $h) => $h->text, array_merge(...array_values($outline ?: [[]])));
    }

    public function test_no_runs_give_no_outline(): void
    {
        $this->assertSame([], (new OutlineDetector())->detect([]));
    }

    public function test_larger_runs_become_headings_with_levels_by_size(): void
    {
        $pages = [1 => [
            $this->textRun(1, 50, 'Economic Outlook', 24),
            $this->textRun(1, 150, 'Regional Trends', 18),
            $this->textRun(1, 300, 'Recent developments', 14),
            ...$this->body(1),
        ]];

        $outline = (new OutlineDetector())->detect($pages);

        $this->assertEquals([1 => [
            new Heading(1, 50, 'Economic Outlook', 1),
            new Heading(1, 150, 'Regional Trends', 2),
            new Heading(1, 300, 'Recent developments', 3),
        ]], $outline);
    }

    public function test_body_font_is_the_one_holding_the_most_characters(): void
    {
        // Size 12 carries most text; size 10 is a footnote, size 13 is barely larger than the body.
        $pages = [1 => [
            $this->textRun(1, 50, 'Proper Heading', 14),
            $this->textRun(1, 100, 'Slightly Larger', 13),
            $this->textRun(1, 900, 'Small footnote text', 10),
            ...array_map(fn (int $i) => $this->textRun(1, 300 + $i * 20, self::BODY, 12), range(0, 9)),
        ]];

        $this->assertSame(['Proper Heading'], $this->texts((new OutlineDetector())->detect($pages)));
    }

    public function test_runs_split_on_one_line_are_joined(): void
    {
        $fiscal = $this->textRun(1, 50, 'Fiscal', 18, 50, 'Bold', 54);
        $pages = [1 => [
            $fiscal,
            $this->textRun(1, 50, 'Policy', 18, 50 + 54 + 4, 'BoldItalic', 54),
            ...$this->body(1),
        ]];

        $this->assertSame(['Fiscal Policy'], $this->texts((new OutlineDetector())->detect($pages)));
    }

    public function test_a_heading_on_two_lines_is_merged(): void
    {
        $pages = [1 => [
            $this->textRun(1, 50, 'Management Discussion', 18),
            $this->textRun(1, 50 + 18 * 1.2 + 2, 'and Analysis', 18),
            ...$this->body(1),
        ]];

        $outline = (new OutlineDetector())->detect($pages);

        $this->assertSame(['Management Discussion and Analysis'], $this->texts($outline));
        $this->assertSame(50.0, $outline[1][0]->top);
    }

    public function test_running_headers_are_excluded(): void
    {
        $pages = [];
        for ($page = 1; $page <= 5; $page++) {
            $pages[$page] = [$this->textRun($page, 10, 'Global Economic Prospects', 14), ...$this->body($page)];
        }
        $pages[2][] = $this->textRun(2, 100, 'Commodity Markets', 18);

        $this->assertSame(['Commodity Markets'], $this->texts((new OutlineDetector())->detect($pages)));
    }

    public function test_captions_lead_sentences_and_urls_are_excluded(): void
    {
        $pages = [1 => [
            $this->textRun(1, 40, 'FIGURE 1.1 Growth prospects', 14),
            $this->textRun(1, 100, 'Table 2', 14),
            $this->textRun(1, 160, 'Box 1.2 Fiscal space', 14),
            $this->textRun(1, 220, 'This chapter reviews the outlook for growth.', 14),
            $this->textRun(1, 280, 'www.worldbank.org', 14),
            $this->textRun(1, 340, 'https://example.com/report', 14),
            $this->textRun(1, 400, 'Real Heading', 14),
            ...$this->body(1),
        ]];

        $this->assertSame(['Real Heading'], $this->texts((new OutlineDetector())->detect($pages)));
    }

    public function test_a_short_question_is_a_heading_but_figures_are_not(): void
    {
        $pages = [1 => [
            $this->textRun(1, 40, 'Why now?', 14),
            $this->textRun(1, 100, '2025 2026', 14),
            $this->textRun(1, 160, 'A. B', 14),
            ...$this->body(1),
        ]];

        // A short question is a heading; numbers and too few letters are not.
        $this->assertSame(['Why now?'], $this->texts((new OutlineDetector())->detect($pages)));
    }

    public function test_a_chapter_opener_is_kept_despite_a_letter_spaced_running_header(): void
    {
        $pages = [];
        for ($page = 1; $page <= 5; $page++) {
            $pages[$page] = [$this->textRun($page, 10, 'C H A P T E R 1', 16), ...$this->body($page)];
        }
        $pages[1][] = $this->textRun(1, 100, 'CHAPTER 1', 24);
        $pages[1][] = $this->textRun(1, 200, 'Global Outlook', 18);

        $outline = (new OutlineDetector())->detect($pages);

        $this->assertSame(['CHAPTER 1', 'Global Outlook'], $this->texts($outline));
        $this->assertSame('Chapter 1', $outline[1][0]->chapter());
        $this->assertSame(1, $outline[1][0]->level);
    }
}
