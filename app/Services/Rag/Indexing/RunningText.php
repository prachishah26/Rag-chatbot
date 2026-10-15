<?php

namespace App\Services\Rag\Indexing;

/** Running headers and footers: text that recurs on many pages ("iMac | Product Environmental Report 6"). */
class RunningText
{
    // Text on at least this share of pages (and three pages) is running text.
    private const MIN_SHARE = 0.2;
    private const MIN_PAGES = 3;
    // Shorter running words ("note", "percent") may be real content when they share a line.
    private const MIN_BANNER_PIECE = 6;

    /**
     * @param  array<int, array<int, TextRun>>  $pages
     * @return array<string, true> normalized running texts
     */
    public function detect(array $pages): array
    {
        $seen = [];
        foreach ($pages as $page => $runs) {
            foreach ($runs as $run) {
                $key = $this->normalize($run->text);
                if ($key !== '') {
                    $seen[$key][$page] = true;
                }
            }
        }

        $minPages = max(self::MIN_PAGES, (int) ceil(count($pages) * self::MIN_SHARE));

        return array_map(fn () => true, array_filter($seen, fn (array $onPages) => count($onPages) >= $minPages));
    }

    public function isRunning(string $text, array $running): bool
    {
        return isset($running[$this->normalize($text)]);
    }

    /**
     * Drops lines made only of running text, and a page number on the first or last line.
     * Other number-only lines (chart axes, call-outs such as "445") are content and stay.
     */
    public function strip(string $pageText, array $running): string
    {
        if (!$running) {
            return $pageText;
        }

        $bannerKeys = $this->bannerKeys($running);
        $lines = explode("\n", $pageText);
        $filled = array_keys(array_filter($lines, fn (string $line) => trim($line) !== ''));
        $edges = $filled ? [min($filled), max($filled)] : [];

        $kept = array_filter($lines, function (string $line, int $i) use ($running, $bannerKeys, $edges) {
            $cells = preg_split('/\s{2,}|\s*\|\s*/u', trim($line), -1, PREG_SPLIT_NO_EMPTY);

            if (!$cells) {
                return true;
            }

            $normalized = $this->normalize($line);
            if ($normalized === '') {
                return !(in_array($i, $edges, true) && preg_match('/^\s*\d{1,4}\s*$/', $line));
            }

            // A banner such as "72 CHAPTER 2 GLOBAL ECONOMIC PROSPECTS | JUNE 2026" joins several runs.
            if (isset($running[$normalized]) || str_replace($bannerKeys, '', $normalized) === '') {
                return false;
            }

            foreach ($cells as $cell) {
                $key = $this->normalize($cell);
                if ($key !== '' && !isset($running[$key])) {
                    return true;
                }
            }

            return false;
        }, ARRAY_FILTER_USE_BOTH);

        return implode("\n", $kept);
    }

    /** Running texts long enough to strip out of a combined banner, longest first. */
    private function bannerKeys(array $running): array
    {
        $keys = array_filter(array_keys($running), fn (string $key) => mb_strlen($key) >= self::MIN_BANNER_PIECE);
        usort($keys, fn (string $a, string $b) => mb_strlen($b) <=> mb_strlen($a));

        return $keys;
    }

    /** Case, spacing, digits and "|" separators vary between pages; letters do not. */
    public function normalize(string $text): string
    {
        return preg_replace('/[\d\s|]+/u', '', mb_strtolower($text));
    }
}
