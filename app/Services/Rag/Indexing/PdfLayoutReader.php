<?php

namespace App\Services\Rag\Indexing;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Reads every text run of a PDF with its font and position, using Poppler's `pdftohtml -xml`.
 * The plain text extractor drops fonts; headings and footnote markers are only visible here.
 */
class PdfLayoutReader
{
    /** @return array<int, array<int, TextRun>> runs keyed by 1-based page number; empty when pdftohtml fails */
    public function read(string $path): array
    {
        try {
            $result = Process::timeout(180)->run([
                config('rag.pdftohtml_binary'), '-xml', '-i', '-q', '-stdout', '-enc', 'UTF-8', $path,
            ]);
        } catch (Throwable $e) {
            Log::warning('pdftohtml could not run; headings and footnotes are skipped.', ['error' => $e->getMessage()]);

            return [];
        }

        if ($result->failed()) {
            Log::warning('pdftohtml failed; headings and footnotes are skipped.', ['error' => $result->errorOutput()]);

            return [];
        }

        return $this->parse($result->output());
    }

    /** @return array<int, array<int, TextRun>> */
    public function parse(string $xml): array
    {
        $fonts = [];
        preg_match_all('/<fontspec id="(\d+)" size="([\d.-]+)" family="([^"]*)"/', $xml, $specs, PREG_SET_ORDER);
        foreach ($specs as [, $id, $size, $family]) {
            // Subset prefixes ("RRTVUI+Helvetica") differ per embedding of the same face.
            $fonts[$id] = [(float) $size, preg_replace('/^[A-Z]{6}\+/', '', $family)];
        }

        $pages = [];
        preg_match_all('/<page number="(\d+)"[^>]*>(.*?)<\/page>/s', $xml, $pageMatches, PREG_SET_ORDER);

        foreach ($pageMatches as [, $number, $body]) {
            $pages[(int) $number] = $this->runs((int) $number, $body, $fonts);
        }

        return $pages;
    }

    private function runs(int $page, string $body, array $fonts): array
    {
        $runs = [];
        $pattern = '/<text top="([\d.-]+)" left="([\d.-]+)" width="([\d.-]+)" height="([\d.-]+)" font="(\d+)"[^>]*>(.*?)<\/text>/s';
        preg_match_all($pattern, $body, $matches, PREG_SET_ORDER);

        foreach ($matches as [, $top, $left, $width, $height, $font, $content]) {
            $text = trim(html_entity_decode(strip_tags($content), ENT_QUOTES | ENT_XML1, 'UTF-8'));

            if ($text === '' || !isset($fonts[$font])) {
                continue;
            }

            $runs[] = new TextRun(
                $page, (float) $top, (float) $left, (float) $width, (float) $height,
                $fonts[$font][0], $fonts[$font][1], $text,
            );
        }

        return $runs;
    }
}
