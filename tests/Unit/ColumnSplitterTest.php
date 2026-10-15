<?php

namespace Tests\Unit;

use App\Services\Rag\ColumnSplitter;
use Tests\TestCase;

class ColumnSplitterTest extends TestCase
{
    public function test_two_column_page_is_read_left_column_then_right_column(): void
    {
        $lines = $this->squeezedLines((new ColumnSplitter())->split($this->fixture('two_column_page.txt')));

        // Left-column sentences are no longer interleaved with the right column.
        $this->assertContains('in today’s dollars of $285.4 million. By comparison,', $lines);
        $this->assertContains('have been at or below $79.7 million in each year, FY', $lines);
        $this->assertContains('services for Congress. In 1980, total GPO employment', $lines);

        // The left column (ending "of Representatives.") comes before the right one.
        $this->assertLessThan(
            array_search('Productivity increases resulting from technology', $lines, true),
            array_search('of Representatives.', $lines, true)
        );
    }

    public function test_side_by_side_tables_keep_their_rows_and_are_separated(): void
    {
        $lines = $this->squeezedLines((new ColumnSplitter())->split($this->fixture('two_column_page.txt')));

        $this->assertContains('2010 93.8 million 107.5 million', $lines);
        $this->assertContains('2010 2,284', $lines);
        $this->assertContains('Fiscal Year Appropriation In Constant Dollars', $lines);
        $this->assertContains('Fiscal Year Headcount', $lines);
    }

    public function test_single_column_financial_table_is_unchanged(): void
    {
        $page = $this->fixture('net_income_layout.txt');

        $this->assertSame($page, (new ColumnSplitter())->split($page));
    }

    public function test_line_crossing_the_gutter_stays_whole_between_split_regions(): void
    {
        $left = ['Alpha column text here', 'more left words here', 'left prose goes on', 'still the left side', 'last left line'];
        $right = ['Right column words', 'more right prose', 'right side keeps going', 'still on the right', 'final right line'];

        $top = array_map(fn ($l, $r) => str_pad($l, 40).$r, $left, $right);
        $full = 'A full width heading that runs straight across the middle gutter';
        $page = implode("\n", [...$top, $full, ...$top]);

        $lines = $this->squeezedLines((new ColumnSplitter())->split($page));

        $this->assertSame([...$left, ...$right, $full, ...$left, ...$right], array_values(array_filter($lines)));
    }

    public function test_label_and_description_list_is_unchanged(): void
    {
        // Short labels far from the gap are a two-column table, not two text columns.
        $page = implode("\n", [
            '     ABBREVIATIONS LIST',
            '       ASC                          Accounting Standards Codification',
            '       CP                           Congressional Publishing',
            '       CSRS                         Civil Service Retirement System',
            '       DOL                          U.S. Department of Labor',
            '       FASAB                        Federal Accounting Standards Advisory Board',
            '       FASB                         Financial Accounting Standards Board',
            '       FDLP                         Federal Depository Library Program',
        ]);

        $this->assertSame($page, (new ColumnSplitter())->split($page));
    }

    public function test_table_with_words_in_its_value_cells_is_unchanged(): void
    {
        $row = str_pad('Revenue from contracts with customers net', 52).'   '.str_pad('Total net', 20).'  1,234';
        $page = implode("\n", array_fill(0, 10, $row));

        $this->assertSame($page, (new ColumnSplitter())->split($page));
    }

    public function test_narrow_text_is_unchanged(): void
    {
        $page = "Item      2018\nNet Income      $ 52,624";

        $this->assertSame($page, (new ColumnSplitter())->split($page));
    }

    private function fixture(string $name): string
    {
        return rtrim(file_get_contents(base_path('tests/Fixtures/'.$name)), "\n");
    }

    /** Lines with indentation removed and column gaps squeezed to one space, as compared by eye. */
    private function squeezedLines(string $text): array
    {
        return array_map(fn (string $line) => preg_replace('/\s+/u', ' ', trim($line)), explode("\n", $text));
    }
}
