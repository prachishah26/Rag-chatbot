<?php

namespace Tests\Unit\Indexing;

use App\Services\Rag\Indexing\TextRun;
use App\Services\Rag\Indexing\VisualPageDetector;
use Tests\TestCase;

class VisualPageDetectorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['rag.vision.pages' => 'auto', 'rag.vision.max_document_pages' => 40]);
    }

    private function runs(int $page, array $texts): array
    {
        return array_map(fn (string $t) => new TextRun($page, 10, 10, 50, 10, 9, 'Helvetica', $t), $texts);
    }

    private function chartPage(int $page): array
    {
        return $this->runs($page, ['445 kg', '99 kg', '346 kg', '12%', '$1,200', '3.5 million', 'Baseline', 'Reductions', 'Total', 'Carbon footprint', 'Production']);
    }

    private function prosePage(int $page): array
    {
        return $this->runs($page, array_fill(0, 12, 'The economy grew steadily over the year as trade and investment recovered.'));
    }

    public function test_many_short_figure_runs_look_visual(): void
    {
        $this->assertTrue((new VisualPageDetector())->looksVisual($this->chartPage(1)));
    }

    public function test_prose_does_not_look_visual(): void
    {
        $this->assertFalse((new VisualPageDetector())->looksVisual($this->prosePage(1)));
    }

    public function test_short_runs_without_enough_figures_do_not_look_visual(): void
    {
        $this->assertFalse((new VisualPageDetector())->looksVisual($this->runs(1, array_fill(0, 12, 'Short label'))));
    }

    public function test_a_page_with_few_runs_does_not_look_visual(): void
    {
        $this->assertFalse((new VisualPageDetector())->looksVisual($this->runs(1, ['1%', '2%', '3%', '4%', '5%'])));
    }

    public function test_auto_mode_selects_visual_pages_only(): void
    {
        $pages = [1 => $this->prosePage(1), 2 => $this->chartPage(2), 3 => $this->prosePage(3)];

        $this->assertSame([2], (new VisualPageDetector())->select($pages));
    }

    public function test_auto_mode_skips_long_documents(): void
    {
        config(['rag.vision.max_document_pages' => 2]);
        $pages = [1 => $this->prosePage(1), 2 => $this->chartPage(2), 3 => $this->prosePage(3)];

        $this->assertSame([], (new VisualPageDetector())->select($pages));
    }

    public function test_none_mode_selects_nothing(): void
    {
        config(['rag.vision.pages' => 'none']);

        $this->assertSame([], (new VisualPageDetector())->select([1 => $this->chartPage(1)]));
    }

    public function test_all_mode_selects_every_page(): void
    {
        config(['rag.vision.pages' => 'all', 'rag.vision.max_document_pages' => 1]);

        $this->assertSame([1, 2], (new VisualPageDetector())->select([1 => $this->prosePage(1), 2 => $this->chartPage(2)]));
    }

    public function test_no_pages_select_nothing(): void
    {
        config(['rag.vision.pages' => 'all']);

        $this->assertSame([], (new VisualPageDetector())->select([]));
    }
}
