<?php

namespace App\Services\Rag\Retrieval;

/**
 * "Summarize the report", "List the important figures": an overview request names no topic, so
 * search matches its generic words ("figures") instead of its meaning. Such questions are answered
 * from the document's summary sections instead.
 */
class BroadQuestion
{
    private const OVERVIEW = '/\b(summari[sz]e|summary|overview|tl;?dr|gist)\b'
        .'|\b(key|main|important|major|notable|headline|top)\s+(figures?|numbers?|statistics|stats|findings?|takeaways?|points?|highlights?|messages?|facts?|data)\b'
        .'|\bwhat\s+is\s+(this|the)\s+(document|report|file|paper)\s+about\b/i';

    // Words an overview request may hold besides its topic; anything else names a topic.
    private const GENERIC_WORDS = [
        'a', 'about', 'all', 'an', 'and', 'are', 'brief', 'briefly', 'can', 'could', 'data', 'do',
        'document', 'does', 'executive', 'fact', 'facts', 'figure', 'figures', 'file', 'finding',
        'findings', 'for', 'from', 'gist', 'give', 'headline', 'highlight', 'highlights', 'i', 'important',
        'in', 'is', 'it', 'its', 'key', 'list', 'main', 'major', 'me', 'message', 'messages', 'need',
        'notable', 'number', 'numbers', 'of', 'on', 'overview', 'paper', 'pdf', 'please', 'point',
        'points', 'provide', 'quick', 'report', 'say', 'says', 'short', 'show', 'stats', 'statistics',
        'summarise', 'summarize', 'summary', 'takeaway', 'takeaways', 'tell', 'that', 'the', 'this',
        'tl', 'dr', 'tldr', 'to', 'top', 'us', 'want', 'what', 'write', 'you',
    ];

    // A chapter may be named, "summarize chapter 3": the router turns it into a filter.
    private const CHAPTER_WORDS = '/^(chapter|\d+|[ivxl]+)$/';

    private const SUMMARY_HEADING = '/\b(summary|overview|key\s+(findings|messages|points)|highlights|abstract|at\s+a\s+glance)\b/i';

    // Added to a broad question's search, so summaries enter the candidate pool.
    public const SEARCH_HINT = 'summary overview key findings';

    public function isBroad(string $question): bool
    {
        if (preg_match(self::OVERVIEW, $question) !== 1) {
            return false;
        }

        $words = preg_split('/[^\pL\pN]+/u', mb_strtolower($question), -1, PREG_SPLIT_NO_EMPTY);
        $topic = array_filter(
            $words,
            fn (string $word) => !in_array($word, self::GENERIC_WORDS, true) && preg_match(self::CHAPTER_WORDS, $word) !== 1
        );

        return $topic === [];
    }

    public function isSummaryHeading(string $headingPath): bool
    {
        return preg_match(self::SUMMARY_HEADING, $headingPath) === 1;
    }
}
