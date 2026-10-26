<?php

namespace Tests\Unit\Indexing;

use App\Services\Rag\Indexing\ContentsListing;
use Tests\TestCase;

class ContentsListingTest extends TestCase
{
    private ContentsListing $contents;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contents = new ContentsListing();
    }

    public function test_dot_leader_entries_are_a_contents_listing(): void
    {
        $text = 'Statistical Appendix ........................................ 163 '
            .'Data and Forecast Conventions ................................ 169 '
            .'2.3 EAP: Outlook ............................................. 60 '
            .'2.4 ECA: Recent developments and outlook ..................... 64';

        $this->assertTrue($this->contents->isListing($text, 'Global Economic Prospects > Contents'));
        $this->assertTrue($this->contents->isListing($text, 'Chapter 1'));
    }

    public function test_contents_heading_marks_a_listing_without_dot_leaders(): void
    {
        $this->assertTrue($this->contents->isListing('Figure 1.1 Global growth 5 Figure 1.2 Trade 7', 'Report > List of Figures'));
        $this->assertTrue($this->contents->isListing('Chapter 1 Global Outlook 1', 'Report > Table of contents'));
        $this->assertTrue($this->contents->isListing('Chapter 1 Global Outlook 1', 'Contents'));
    }

    public function test_prose_is_not_a_listing(): void
    {
        $text = 'Global growth is projected to slow to 2.5 percent in 2026... before recovering to 2.8 percent in 2027.';

        $this->assertFalse($this->contents->isListing($text, 'Chapter 1 > GLOBAL OUTLOOK > Summary'));
    }

    public function test_a_few_dot_leaders_in_long_prose_are_not_a_listing(): void
    {
        $text = str_repeat('Revenue grew steadily as demand for printing rose across agencies. ', 20)
            .'See note ..... 4, table ..... 5 and chart ..... 6.';

        $this->assertFalse($this->contents->isListing($text, 'Financial Statements'));
    }

    public function test_a_heading_that_only_mentions_contents_is_not_a_listing(): void
    {
        $this->assertFalse($this->contents->isListing('Recycled content rose to 22 percent.', 'Product > Recycled contents'));
    }
}
