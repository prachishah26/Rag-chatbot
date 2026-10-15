<?php

namespace Tests\Unit;

use App\Services\Rag\TableParser;
use PHPUnit\Framework\TestCase;

class TableParserTest extends TestCase
{
    private function layout(): string
    {
        return file_get_contents(__DIR__.'/../Fixtures/net_income_layout.txt');
    }

    public function test_it_finds_the_whole_table_around_a_row(): void
    {
        $text = $this->layout();
        $offset = strpos($text, '(28,745)');

        [$start, $end] = (new TableParser())->regionAround($text, $offset);
        $block = substr($text, $start, $end - $start);

        $this->assertStringStartsWith('Summary of Net Income (in thousands)', $block);
        $this->assertStringEndsWith('$     38,295', $block);
        $this->assertStringNotContainsString('Excluding income', $block);
        $this->assertStringNotContainsString('retained earnings increased', $block);
    }

    public function test_narrative_text_is_not_a_table(): void
    {
        $text = $this->layout();

        $this->assertNull((new TableParser())->regionAround($text, strpos($text, 'retained earnings increased')));
    }

    public function test_it_parses_rows_merging_currency_signs_and_wrapped_labels(): void
    {
        $text = $this->layout();
        [$start, $end] = (new TableParser())->regionAround($text, strpos($text, 'Net Income  '));

        $rows = (new TableParser())->parse(substr($text, $start, $end - $start));

        $this->assertSame([
            ['Summary of Net Income (in thousands)', '2018', '2017'],
            ['Net Income', '$ 52,624', '$ 58,876'],
            ['Less: Passport Capital Projects Reserve', '(28,745)', '(14,783)'],
            ["Decrease in Workers' Compensation Liability", '(3,495)', '(5,798)'],
            ["Net Income After Capital Projects Reserve and Workers' Compensation Liability Adjustment", '$ 20,384', '$ 38,295'],
        ], $rows);
    }

    public function test_short_rows_are_padded_so_numbers_stay_right_aligned(): void
    {
        $rows = (new TableParser())->parse("Item          2018      2017\nTotal                   500");

        $this->assertSame([['Item', '2018', '2017'], ['Total', '', '500']], $rows);
    }

    public function test_markdown_rendering(): void
    {
        $md = (new TableParser())->toMarkdown([['Item', '2018'], ['Net Income', '$ 52,624']]);

        $this->assertSame("| Item | 2018 |\n| --- | --- |\n| Net Income | $ 52,624 |", $md);
    }

    public function test_header_row_detection(): void
    {
        $parser = new TableParser();

        $this->assertTrue($parser->isHeaderRow(['Summary of Net Income (in thousands)', '2018', '2017']));
        $this->assertFalse($parser->isHeaderRow(['Net Income', '$ 52,624', '$ 58,876']));
    }

    public function test_long_runs_of_short_lines_end_the_table(): void
    {
        $text = "Item  2018  2017\nRevenue  100  90\nHighlights\nFaster delivery\nNew services\nMore titles\nCosts  50  40";

        [$start, $end] = (new TableParser())->regionAround($text, 0);

        $this->assertSame("Item  2018  2017\nRevenue  100  90", substr($text, $start, $end - $start));
    }

    public function test_regions_lists_every_table_in_page_text(): void
    {
        $text = "Intro prose sentence that explains the page in words.\n\n"
            ."Revenue        2018      2017\n"
            ."Printing        375.7     360.1\n"
            ."Publishing      120.4     118.9\n\n"
            ."More prose follows the first table and runs on for a while.\n\n"
            ."Mode       100V      115V\n"
            ."Off        0.27W     0.27W\n"
            ."Sleep      0.56W     0.57W\n";

        $parser = new TableParser();
        $regions = $parser->regions($text);

        $this->assertCount(2, $regions);
        $this->assertStringStartsWith('Revenue', substr($text, $regions[0][0], $regions[0][1] - $regions[0][0]));
        $this->assertStringEndsWith('118.9', substr($text, $regions[0][0], $regions[0][1] - $regions[0][0]));
        $this->assertStringStartsWith('Mode', substr($text, $regions[1][0], $regions[1][1] - $regions[1][0]));
    }
}
