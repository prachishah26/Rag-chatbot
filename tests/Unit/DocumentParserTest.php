<?php

namespace Tests\Unit;

use App\Services\Rag\DocumentParser;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class DocumentParserTest extends TestCase
{
    public function test_pdf_text_is_extracted_with_layout_preserved(): void
    {
        Process::fake([
            '*' => Process::result("Item      2018\fNet Income      $ 52,624\n"),
        ]);

        $text = app(DocumentParser::class)->extract('/tmp/report.pdf', 'application/pdf', 'pdf');

        $this->assertSame("Item      2018\n\nNet Income      $ 52,624", $text);

        Process::assertRan(fn (PendingProcess $process) => $process->command === [
            'pdftotext', '-layout', '-enc', 'UTF-8', '/tmp/report.pdf', '-',
        ]);
    }

    public function test_two_column_pdf_pages_are_read_one_column_at_a_time(): void
    {
        $page = file_get_contents(base_path('tests/Fixtures/two_column_page.txt'));
        Process::fake(['*' => Process::result("Cover page\f".$page)]);

        $text = app(DocumentParser::class)->extract('/tmp/report.pdf', 'application/pdf', 'pdf');

        $this->assertStringStartsWith("Cover page\n\n", $text);
        $this->assertMatchesRegularExpression('/each year, FY\n\s*2014-2018\./', $text);
        $this->assertDoesNotMatchRegularExpression('/FY\s+Fiscal Year/', $text);
    }

    public function test_fallback_parser_keeps_one_entry_per_page(): void
    {
        // pdftotext missing or failing: smalot/pdfparser must still number the pages.
        Process::fake(['*' => Process::result(output: '', errorOutput: 'not found', exitCode: 127)]);

        $pages = app(DocumentParser::class)->pages(base_path('tests/Fixtures/two_pages.pdf'), 'application/pdf', 'pdf');

        $this->assertSame([1, 2], array_keys($pages));
        $this->assertStringContainsString('First page text', $pages[1]);
        $this->assertStringContainsString('Second page text', $pages[2]);
    }
}
