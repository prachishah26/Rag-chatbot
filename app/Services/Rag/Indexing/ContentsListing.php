<?php

namespace App\Services\Rag\Indexing;

/**
 * Recognises a table of contents or a list of figures/tables. Such a page names every topic of
 * the report ("Figure 1.1 ... 5"), so it matches broad questions word for word yet answers none.
 */
class ContentsListing
{
    // "Statistical Appendix ........ 163": four or more leader dots, then a page number.
    private const DOT_LEADER = '/\.{4,}\s*\d{1,4}\b/';

    // A listing has several entries, and leader dots make up a good share of its characters.
    private const MIN_LEADERS = 3;
    private const MIN_LEADER_SHARE = 0.2;

    private const HEADING = '/^(table\s+of\s+)?contents$|^list\s+of\s+(figures|tables|boxes|charts|maps)$/i';

    public function isListing(string $text, string $headingPath = ''): bool
    {
        return $this->hasListingHeading($headingPath) || $this->hasDotLeaders($text);
    }

    private function hasListingHeading(string $headingPath): bool
    {
        $parts = explode('>', $headingPath);

        return preg_match(self::HEADING, trim((string) end($parts))) === 1;
    }

    private function hasDotLeaders(string $text): bool
    {
        $count = preg_match_all(self::DOT_LEADER, $text, $matches);

        if ($count < self::MIN_LEADERS) {
            return false;
        }

        $leaderChars = array_sum(array_map('strlen', $matches[0]));

        return $leaderChars / max(1, strlen($text)) >= self::MIN_LEADER_SHARE;
    }
}
