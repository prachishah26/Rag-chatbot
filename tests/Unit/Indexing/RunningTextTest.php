<?php

namespace Tests\Unit\Indexing;

use App\Services\Rag\Indexing\RunningText;
use App\Services\Rag\Indexing\TextRun;
use PHPUnit\Framework\TestCase;

class RunningTextTest extends TestCase
{
    private function textRun(int $page, string $text): TextRun
    {
        return new TextRun($page, 10, 50, 200, 10, 8, 'Helvetica', $text);
    }

    /** @param  array<int, array<int, string>>  $texts  per page */
    private function pages(int $count, array $texts): array
    {
        $pages = [];
        for ($page = 1; $page <= $count; $page++) {
            $pages[$page] = array_map(fn (string $t) => $this->textRun($page, $t), $texts[$page] ?? ['Body text on page '.$page]);
        }

        return $pages;
    }

    public function test_text_on_three_of_five_pages_is_running(): void
    {
        $pages = $this->pages(5, [1 => ['Annual Report 1'], 2 => ['Annual Report 2'], 3 => ['ANNUAL  REPORT | 3']]);

        $running = (new RunningText())->detect($pages);

        $this->assertArrayHasKey('annualreport', $running);
        $this->assertTrue((new RunningText())->isRunning('Annual Report 9', $running));
    }

    public function test_text_on_only_two_pages_is_not_running(): void
    {
        $pages = $this->pages(5, [1 => ['Annual Report'], 2 => ['Annual Report']]);

        $this->assertArrayNotHasKey('annualreport', (new RunningText())->detect($pages));
    }

    public function test_text_on_fewer_than_twenty_percent_of_pages_is_not_running(): void
    {
        // 20 pages need at least 4.
        $pages = $this->pages(20, [1 => ['Annual Report'], 2 => ['Annual Report'], 3 => ['Annual Report']]);
        $this->assertArrayNotHasKey('annualreport', (new RunningText())->detect($pages));

        $pages = $this->pages(20, [1 => ['Annual Report'], 2 => ['Annual Report'], 3 => ['Annual Report'], 4 => ['Annual Report']]);
        $this->assertArrayHasKey('annualreport', (new RunningText())->detect($pages));
    }

    public function test_strip_keeps_text_when_nothing_is_running(): void
    {
        $this->assertSame("72\nSome text", (new RunningText())->strip("72\nSome text", []));
    }

    public function test_strip_drops_running_lines_and_page_numbers_but_keeps_content(): void
    {
        $banner = [];
        for ($page = 1; $page <= 5; $page++) {
            $banner[$page] = ['CHAPTER 2', 'GLOBAL ECONOMIC PROSPECTS | JUNE 2026'];
        }
        $runningText = new RunningText();
        $running = $runningText->detect($this->pages(5, $banner));

        $page = implode("\n", [
            '72 CHAPTER 2 GLOBAL ECONOMIC PROSPECTS | JUNE 2026',
            'Growth in South Asia slowed in 2025.',
            '',
            'CHAPTER 2       GLOBAL ECONOMIC PROSPECTS | JUNE 2026',
            'Chapter 2 reviews regional prospects.',
            '        73',
        ]);

        $this->assertSame(
            "Growth in South Asia slowed in 2025.\n\nChapter 2 reviews regional prospects.",
            $runningText->strip($page, $running)
        );
    }

    public function test_normalize_ignores_case_digits_spaces_and_bars(): void
    {
        $this->assertSame('imacproductreport', (new RunningText())->normalize('iMac | Product  Report 6'));
    }

    public function test_number_only_lines_are_kept_except_a_page_number_at_the_edge(): void
    {
        $running = ['productenvironmentalreport' => true];
        $page = "Baseline emissions\n445\n2019   2020   2021\nProduct Environmental Report\n6";

        $this->assertSame(
            "Baseline emissions\n445\n2019   2020   2021",
            (new \App\Services\Rag\Indexing\RunningText())->strip($page, $running)
        );
    }
}
