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

    public function test_a_period_sub_header_starts_its_own_markdown_header(): void
    {
        $md = (new TableParser())->toMarkdown([
            ['', '2025e', '2026f'],
            ['Saudi Arabia', '4.5', '3.1'],
            ['', '2025/26e', '2026/27f'],
            ['Pakistan 1', '3.0', '3.2'],
        ]);

        $this->assertSame(
            "|  | 2025e | 2026f |\n| --- | --- | --- |\n| Saudi Arabia | 4.5 | 3.1 |\n"
            ."|  | 2025/26e | 2026/27f |\n| --- | --- | --- |\n| Pakistan 1 | 3.0 | 3.2 |",
            $md
        );
    }

    public function test_labelled_rows_name_each_value_by_its_nearest_header(): void
    {
        $table = "Table: Forecasts\n"
            ."|  | 2025e | 2026f |\n| --- | --- | --- |\n| Saudi Arabia | 4.5 | 3.1 |\n"
            ."|  | 2025/26e | 2026/27f |\n| --- | --- | --- |\n| Pakistan 1 | 3.0 | 3.2 |\n| Lebanon | .. |  |\n\nNotes:\n[^1] Factor cost.";

        $this->assertSame(
            "Table: Forecasts\n"
            ."Saudi Arabia: 2025e = 4.5; 2026f = 3.1\n"
            ."Pakistan 1: 2025/26e = 3.0; 2026/27f = 3.2\n"
            ."Lebanon: 2025/26e = ..\n\nNotes:\n[^1] Factor cost.",
            (new TableParser())->labelledRows($table)
        );
    }

    public function test_a_table_whose_first_row_is_data_is_not_relabelled(): void
    {
        $table = "Table: Carbon Footprint\n| • Production | 50% |\n| --- | --- |\n| • Transportation | 5% |";

        $this->assertSame($table, (new TableParser())->labelledRows($table));
    }

    public function test_a_data_row_of_plain_years_is_not_a_period_sub_header(): void
    {
        $parser = new TableParser();

        $this->assertFalse($parser->isPeriodRow(['Founded', '1990', '2010']));
        $this->assertTrue($parser->isPeriodRow(['', '2025/26e', '2026/27f']));
        $this->assertTrue($parser->isPeriodRow(['', '2025e', '2026f']));
        $this->assertStringNotContainsString(
            "| Founded | 1990 | 2010 |\n| ---",
            $parser->toMarkdown([['', '2024', '2025'], ['Founded', '1990', '2010'], ['X', '1', '2']])
        );
    }

    public function test_a_period_row_ending_the_table_gets_no_dangling_separator(): void
    {
        $md = (new TableParser())->toMarkdown([['', '2025', '2026'], ['A', '1', '2'], ['', '2025/26', '2026/27']]);

        $this->assertStringEndsWith('|  | 2025/26 | 2026/27 |', $md);
    }

    public function test_labelled_rows_respect_escaped_pipes_in_cells(): void
    {
        $table = "| Item | Value |\n| --- | --- |\n| a \\| b | 5 |";

        $this->assertSame('a | b: Value = 5', (new TableParser())->labelledRows($table));
    }

    public function test_a_prose_line_with_one_fiscal_year_is_not_a_table_row(): void
    {
        $text = "Growth was strong.\n\nIn   FY2023/24  and more\nRevenue  100  90\nCosts  50  40";

        [$start] = (new TableParser())->regions($text)[0];

        $this->assertSame(strpos($text, 'Revenue'), $start);
    }

    public function test_header_row_detection(): void
    {
        $parser = new TableParser();

        $this->assertTrue($parser->isHeaderRow(['Summary of Net Income (in thousands)', '2018', '2017']));
        $this->assertFalse($parser->isHeaderRow(['Net Income', '$ 52,624', '$ 58,876']));
    }

    public function test_fiscal_year_sub_headers_stay_inside_the_table(): void
    {
        $text = "Intro prose sentence that explains the page in words.\n\n"
            ."                     2023      2024      2025e     2026f\n"
            ."Calendar-year basis\n"
            ."  Saudi Arabia       0.5       2.6       4.5       3.1\n"
            ."Fiscal-year basis 4  2023/24   2024/25   2025/26e  2026/27f\n"
            ."  Afghanistan        2.3       1.9       4.8       4.0\n"
            ."                     2022/23   2023/24   2024/25   2025/26e\n"
            ."  Pakistan 1         -0.2      2.6       3.1       3.0\n";

        $parser = new TableParser();
        [$start, $end] = $parser->regionAround($text, strpos($text, 'Saudi'));
        $rows = $parser->parse(substr($text, $start, $end - $start));

        $this->assertStringEndsWith('3.0', substr($text, $start, $end - $start));
        $this->assertSame([
            ['', '2023', '2024', '2025e', '2026f'],
            ['Calendar-year basis Saudi Arabia', '0.5', '2.6', '4.5', '3.1'],
            ['Fiscal-year basis 4', '2023/24', '2024/25', '2025/26e', '2026/27f'],
            ['Afghanistan', '2.3', '1.9', '4.8', '4.0'],
            ['', '2022/23', '2023/24', '2024/25', '2025/26e'],
            ['Pakistan 1', '-0.2', '2.6', '3.1', '3.0'],
        ], $rows);
        $this->assertTrue($parser->isPeriodRow($rows[4]));
        $this->assertFalse($parser->isPeriodRow($rows[5]));
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
