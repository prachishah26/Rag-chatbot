<?php

namespace App\Services\Rag;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Smalot\PdfParser\Parser;
use Throwable;

class DocumentParser
{
    public function __construct(private ColumnSplitter $columns = new ColumnSplitter()) {}

    public function extract(string $path, string $mimeType, string $extension): string
    {
        return trim(implode("\n\n", $this->pages($path, $mimeType, $extension)));
    }

    /** @return array<int, string> text of each page, keyed by 1-based page number */
    public function pages(string $path, string $mimeType, string $extension): array
    {
        if ($extension === 'txt' || str_contains($mimeType, 'text/plain')) {
            $text = file_get_contents($path);
            if ($text === false) {
                throw new RuntimeException('Unable to read text file.');
            }

            return [1 => trim($text)];
        }

        if ($extension === 'pdf' || $mimeType === 'application/pdf') {
            return $this->extractPdfWithLayout($path)
                ?? $this->extractPdfPages($path);
        }

        throw new RuntimeException('Unsupported document type.');
    }

    /**
     * Poppler's pdftotext keeps table rows and columns intact; smalot/pdfparser flattens
     * them into one run of text. Returns null when pdftotext is unavailable or fails.
     */
    private function extractPdfWithLayout(string $path): ?array
    {
        try {
            $result = Process::timeout(120)->run([
                config('rag.pdftotext_binary'), '-layout', '-enc', 'UTF-8', $path, '-',
            ]);
        } catch (Throwable $e) {
            Log::warning('pdftotext could not run; falling back to smalot/pdfparser.', ['error' => $e->getMessage()]);

            return null;
        }

        if ($result->failed() || trim($result->output()) === '') {
            Log::warning('pdftotext failed; falling back to smalot/pdfparser.', ['error' => $result->errorOutput()]);

            return null;
        }

        // Form feeds separate pages (the last one ends the document); two-column pages are read one column at a time.
        $pages = explode("\f", rtrim($result->output(), "\f\n"));
        $pages = array_map(fn (string $page) => $this->columns->split($page), $pages);

        return array_combine(range(1, count($pages)), $pages);
    }

    /**
     * Fallback without Poppler: smalot/pdfparser, page by page, so page numbers still line up
     * with headings and footnotes read from the layout.
     *
     * @return array<int, string>
     */
    private function extractPdfPages(string $path): array
    {
        $pages = array_map(fn ($page) => trim($page->getText()), (new Parser())->parseFile($path)->getPages());

        return $pages ? array_combine(range(1, count($pages)), $pages) : [1 => ''];
    }
}
