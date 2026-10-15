<?php

namespace Tests\Unit\Indexing;

use App\Services\Rag\Indexing\BuildState;
use App\Services\Rag\Indexing\Element;
use App\Services\Rag\Indexing\Heading;
use App\Services\Rag\Indexing\RegionCatalog;
use App\Services\Rag\Indexing\Section;
use App\Services\Rag\Indexing\StructureBuilder;
use PHPUnit\Framework\TestCase;

class StructureBuilderTest extends TestCase
{
    private function builder(): StructureBuilder
    {
        return new StructureBuilder(regions: new RegionCatalog(['South Asia' => ['SAR', 'India']]));
    }

    /** @return array<int, array{0: string, 1: array<int, string>}> heading path text and element texts per section */
    private function summary(array $sections): array
    {
        return array_map(fn (Section $s) => [
            $s->headingPathText(),
            array_map(fn (Element $e) => $e->text, $s->elements),
        ], $sections);
    }

    public function test_headings_on_their_own_line_open_sections_with_their_path(): void
    {
        $text = "Introduction\nGrowth slowed this\nyear.\n\nOutlook\nPrices will rise.\n\nInvestment\nInvestment fell.";
        $outline = [1 => [
            new Heading(1, 10, 'Introduction', 1),
            new Heading(1, 50, 'Outlook', 2),
            new Heading(1, 90, 'Investment', 1),
        ]];

        $sections = $this->builder()->build([1 => $text], $outline);

        $this->assertSame([
            ['Introduction', ['Growth slowed this year.']],
            ['Introduction > Outlook', ['Prices will rise.']],
            ['Investment', ['Investment fell.']],
        ], $this->summary($sections));
        $this->assertSame(1, $sections[0]->elements[0]->page);
    }

    public function test_text_before_any_heading_is_a_section_without_a_path(): void
    {
        $sections = $this->builder()->build([1 => 'Cover text.', 2 => "Summary\nKey points."], [2 => [new Heading(2, 10, 'Summary', 1)]]);

        $this->assertSame([['', ['Cover text.']], ['Summary', ['Key points.']]], $this->summary($sections));
    }

    public function test_a_heading_set_on_two_lines_is_matched_across_both(): void
    {
        $text = "Financial Position and Results for\nFiscal Year 2018\nRevenue grew.";
        $outline = [1 => [new Heading(1, 10, 'Financial Position and Results for Fiscal Year 2018', 1)]];

        $this->assertSame(
            [['Financial Position and Results for Fiscal Year 2018', ['Revenue grew.']]],
            $this->summary($this->builder()->build([1 => $text], $outline))
        );
    }

    public function test_a_heading_followed_by_a_column_gap_keeps_the_rest_as_prose(): void
    {
        $text = "Intro text.\nDefinitions      Bio-based plastics: made from plants.\nMore detail.";
        $outline = [1 => [new Heading(1, 10, 'Intro', 1), new Heading(1, 40, 'Definitions', 1)]];
        // "Intro" is not on a line of its own, so it opens at the start of the page.
        $sections = $this->builder()->build([1 => $text], $outline);

        $this->assertSame([
            ['Intro', ['Intro text.']],
            ['Definitions', ['Bio-based plastics: made from plants. More detail.']],
        ], $this->summary($sections));
    }

    public function test_a_heading_running_into_a_sentence_is_not_a_margin_heading(): void
    {
        $text = "Top\nDefinitions are given below.";
        $outline = [1 => [new Heading(1, 10, 'Top', 1), new Heading(1, 40, 'Definitions', 2)]];

        $this->assertSame(
            [['Top', ['Definitions are given below.']]],
            $this->summary($this->builder()->build([1 => $text], $outline))
        );
    }

    public function test_a_top_heading_not_found_in_the_text_opens_at_page_start(): void
    {
        $sections = $this->builder()->build(
            [1 => 'Before.', 2 => 'Our footprint fell by half.'],
            [2 => [new Heading(2, 10, 'Carbon Footprint', 1)]]
        );

        $this->assertSame([['', ['Before.']], ['Carbon Footprint', ['Our footprint fell by half.']]], $this->summary($sections));
    }

    public function test_a_chapter_heading_sets_the_chapter_and_resets_the_path_and_regions_come_from_the_path(): void
    {
        $pages = [
            1 => "Preface\nWelcome.",
            2 => "CHAPTER 2\nRegional outlooks.\n\nSouth Asia\nGrowth is strong.\n\nRecent developments\nInflation eased.",
        ];
        $outline = [
            1 => [new Heading(1, 10, 'Preface', 1)],
            2 => [
                new Heading(2, 10, 'CHAPTER 2', 1),
                new Heading(2, 40, 'South Asia', 2),
                new Heading(2, 80, 'Recent developments', 3),
            ],
        ];

        $sections = $this->builder()->build($pages, $outline);

        $this->assertSame([
            ['Preface', null, null],
            ['Chapter 2', 'Chapter 2', null],
            ['Chapter 2 > South Asia', 'Chapter 2', 'South Asia'],
            ['Chapter 2 > South Asia > Recent developments', 'Chapter 2', 'South Asia'],
        ], array_map(fn (Section $s) => [$s->headingPathText(), $s->chapter, $s->region], $sections));
    }

    public function test_a_table_becomes_a_markdown_table_element_titled_by_the_current_heading(): void
    {
        $text = implode("\n", [
            'Fiscal Data',
            'The table shows revenue.',
            '',
            'Revenue        2024     2025',
            'Taxes           120      130',
            'Grants           15       18',
            '',
            'After the table.',
        ]);

        $sections = $this->builder()->build([1 => $text], [1 => [new Heading(1, 10, 'Fiscal Data', 1)]]);

        $this->assertCount(1, $sections);
        $elements = $sections[0]->elements;
        $this->assertSame([Element::TEXT, Element::TABLE, Element::TEXT], array_map(fn (Element $e) => $e->type, $elements));
        $this->assertSame('The table shows revenue.', $elements[0]->text);
        $this->assertSame(
            "Table: Fiscal Data\n| Revenue | 2024 | 2025 |\n| --- | --- | --- |\n| Taxes | 120 | 130 |\n| Grants | 15 | 18 |",
            $elements[1]->text
        );
        $this->assertSame('After the table.', $elements[2]->text);
    }

    public function test_a_table_before_any_heading_has_no_title(): void
    {
        $sections = $this->builder()->build([1 => "Item     2024     2025\nTaxes     120      130\nGrants     15       18"]);

        $this->assertSame("| Item | 2024 | 2025 |\n| --- | --- | --- |\n| Taxes | 120 | 130 |\n| Grants | 15 | 18 |", $sections[0]->elements[0]->text);
    }

    public function test_visuals_are_appended_after_the_page_text(): void
    {
        $sections = $this->builder()->build(
            [1 => "Charts\nSee the chart.", 2 => 'Next page.'],
            [1 => [new Heading(1, 10, 'Charts', 1)]],
            [],
            [1 => '  Visual content on page 1: A bar chart.  ', 2 => '   ']
        );

        $elements = $sections[0]->elements;
        $this->assertSame([Element::TEXT, Element::VISUAL, Element::TEXT], array_map(fn (Element $e) => $e->type, $elements));
        $this->assertSame('Visual content on page 1: A bar chart.', $elements[1]->text);
        $this->assertSame(1, $elements[1]->page);
    }

    public function test_footnote_markers_on_the_page_are_annotated(): void
    {
        $sections = $this->builder()->build(
            [1 => 'Products use recycled content1 today.', 2 => 'Page two content1 stays.'],
            [],
            [1 => [1]]
        );

        $this->assertSame(['Products use recycled content[^1] today.', 'Page two content1 stays.'], array_map(
            fn (Element $e) => $e->text,
            $sections[0]->elements
        ));
    }

    public function test_a_heading_with_a_footnote_marker_still_matches(): void
    {
        $sections = $this->builder()->build(
            [1 => "Recycled Materials3\nWe use more."],
            [1 => [new Heading(1, 10, 'Recycled Materials', 1)]],
            [1 => [3]]
        );

        $this->assertSame([['Recycled Materials', ['We use more.']]], $this->summary($sections));
    }

    public function test_build_state_drops_empty_sections_and_keeps_the_heading_stack(): void
    {
        $state = new BuildState();
        $this->assertNull($state->currentHeading());

        $state->openHeading(new Heading(1, 10, 'Empty', 1), null);
        $state->openHeading(new Heading(1, 20, 'Top', 1), null);
        $state->openHeading(new Heading(1, 30, 'Child', 2), 'South Asia');
        $this->assertSame('Child', $state->currentHeading());
        $state->addElement(new Element(Element::TEXT, 'x', 1));
        $state->openHeading(new Heading(1, 40, 'Sibling', 2), null);
        $state->addElement(new Element(Element::TEXT, 'y', 1));

        $sections = $state->finish();

        $this->assertSame(
            [['Top > Child', 'South Asia'], ['Top > Sibling', null]],
            array_map(fn (Section $s) => [$s->headingPathText(), $s->region], $sections)
        );
    }
}
