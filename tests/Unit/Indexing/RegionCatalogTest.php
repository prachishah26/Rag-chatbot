<?php

namespace Tests\Unit\Indexing;

use App\Services\Rag\Indexing\RegionCatalog;
use PHPUnit\Framework\TestCase;

class RegionCatalogTest extends TestCase
{
    private function catalog(): RegionCatalog
    {
        return new RegionCatalog([
            'South Asia' => ['SAR', 'South Asia', 'India', 'Sri Lanka'],
            'Middle East and North Africa' => ['MNA', 'Middle East', 'Egypt'],
            'Sub-Saharan Africa' => ['SSA', 'Africa', 'Nigeria'],
        ]);
    }

    public function test_all_returns_the_regions_given(): void
    {
        $this->assertSame(['South Asia', 'Middle East and North Africa', 'Sub-Saharan Africa'], array_keys($this->catalog()->all()));
    }

    public function test_for_heading_matches_the_region_name_exactly(): void
    {
        $catalog = $this->catalog();

        $this->assertSame('South Asia', $catalog->forHeading('South Asia'));
        $this->assertSame('South Asia', $catalog->forHeading('SOUTH ASIA'));
        $this->assertSame('Sub-Saharan Africa', $catalog->forHeading('Sub-Saharan Africa'));
    }

    public function test_for_heading_ignores_headings_that_only_contain_a_region(): void
    {
        $catalog = $this->catalog();

        $this->assertNull($catalog->forHeading('South Asia outlook'));
        $this->assertNull($catalog->forHeading('Recent developments in India'));
        $this->assertNull($catalog->forHeading('Africa'));
    }

    public function test_in_question_matches_names_in_any_case(): void
    {
        $this->assertSame('South Asia', $this->catalog()->inQuestion('How fast will south asia grow?'));
    }

    public function test_abbreviations_match_only_in_capitals(): void
    {
        $catalog = $this->catalog();

        $this->assertSame('South Asia', $catalog->inQuestion('What is the SAR outlook?'));
        $this->assertNull($catalog->inQuestion('Is the sar figure revised?'));
    }

    public function test_a_country_maps_to_its_region(): void
    {
        $catalog = $this->catalog();

        $this->assertSame('South Asia', $catalog->inQuestion("What is India's inflation rate?"));
        $this->assertSame('Middle East and North Africa', $catalog->inQuestion('Growth in Egypt'));
        $this->assertSame('Sub-Saharan Africa', $catalog->inQuestion('Debt in Nigeria'));
    }

    public function test_the_longest_name_wins(): void
    {
        $this->assertSame(
            'Middle East and North Africa',
            $this->catalog()->inQuestion('What about the Middle East and North Africa?')
        );
    }

    public function test_a_question_without_a_region_returns_null(): void
    {
        $this->assertNull($this->catalog()->inQuestion('What is global inflation?'));
    }
}
