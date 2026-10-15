<?php

namespace App\Services\Rag\Indexing;

/**
 * Sections → parent and child chunks. Prose parents (~900 tokens) are whole sentences that stay
 * inside one section; each parent is cut into overlapping children (~280 tokens) for search.
 * A table or a chart description is one element and one chunk, never cut by a character count.
 * Endnotes cited in a child ("[^7]") are appended to it, so the caveat is indexed with the claim.
 */
class ChunkBuilder
{
    public function __construct(private SentenceSplitter $sentences = new SentenceSplitter()) {}

    /**
     * @param  array<int, Section>  $sections
     * @param  array<int, string>  $endnotes  note text by number
     * @return array<int, Chunk>
     */
    public function build(array $sections, array $endnotes = []): array
    {
        $chunks = [];
        $parentIndex = 0;

        foreach ($sections as $section) {
            foreach ($this->parents($section) as [$type, $sentences, $page]) {
                $parentText = $this->join($type, $sentences);

                foreach ($this->children($type, $sentences) as $childText) {
                    $chunks[] = new Chunk(
                        index: count($chunks),
                        parentIndex: $parentIndex,
                        type: $type,
                        text: $this->withNotes($childText, $endnotes),
                        parentText: $this->withNotes($parentText, $endnotes),
                        page: $page,
                        headingPath: $section->headingPathText(),
                        chapter: $section->chapter,
                        region: $section->region,
                    );
                }

                $parentIndex++;
            }
        }

        return $chunks;
    }

    /**
     * Groups a section's sentences into parents of up to parent_size characters; tables and
     * visual descriptions stand alone.
     *
     * @return array<int, array{0: string, 1: array<int, string>, 2: int}> type, sentences (or rows), first page
     */
    private function parents(Section $section): array
    {
        $limit = max(400, (int) config('rag.chunking.parent_size'));
        $parents = [];
        $buffer = [];
        $length = 0;
        $page = null;

        $flush = function () use (&$parents, &$buffer, &$length, &$page) {
            if ($buffer) {
                $parents[] = [Element::TEXT, $buffer, $page];
            }
            $buffer = [];
            $length = 0;
            $page = null;
        };

        foreach ($section->elements as $element) {
            if ($element->type !== Element::TEXT) {
                $flush();
                foreach ($this->splitBlock($element->text, $limit) as $piece) {
                    $parents[] = [$element->type, [$piece], $element->page];
                }
                continue;
            }

            foreach ($this->fit($this->sentences->split($element->text)) as $sentence) {
                if ($buffer && $length + mb_strlen($sentence) + 1 > $limit) {
                    $flush();
                }
                $page ??= $element->page;
                $buffer[] = $sentence;
                $length += mb_strlen($sentence) + 1;
            }
        }

        $flush();

        return $parents;
    }

    /** A "sentence" longer than a child (a list with no periods) is cut at word boundaries. */
    private function fit(array $sentences): array
    {
        $limit = max(200, (int) config('rag.chunking.child_size'));
        $fitted = [];

        foreach ($sentences as $sentence) {
            if (mb_strlen($sentence) <= $limit) {
                $fitted[] = $sentence;
                continue;
            }

            $piece = '';
            foreach (preg_split('/\s+/u', $sentence) as $word) {
                if ($piece !== '' && mb_strlen($piece) + mb_strlen($word) + 1 > $limit) {
                    $fitted[] = $piece;
                    $piece = '';
                }
                $piece = ltrim($piece.' '.$word);
            }
            $fitted[] = $piece;
        }

        return $fitted;
    }

    /** @return array<int, string> children of a parent: overlapping sentence windows */
    private function children(string $type, array $sentences): array
    {
        if ($type !== Element::TEXT) {
            return [$sentences[0]];
        }

        $limit = max(200, (int) config('rag.chunking.child_size'));
        $overlap = max(0, (int) config('rag.chunking.overlap_sentences'));
        $children = [];
        $start = 0;
        $count = count($sentences);

        while ($start < $count) {
            $end = $start;
            $length = 0;

            while ($end < $count && ($end === $start || $length + mb_strlen($sentences[$end]) + 1 <= $limit)) {
                $length += mb_strlen($sentences[$end]) + 1;
                $end++;
            }

            $children[] = implode(' ', array_slice($sentences, $start, $end - $start));

            if ($end >= $count) {
                break;
            }

            // Step back by the overlap, but always move forward.
            $start = max($start + 1, $end - $overlap);
        }

        return $children;
    }

    /** A block longer than the limit (a long table) is cut between rows, repeating its first lines. */
    private function splitBlock(string $text, int $limit): array
    {
        if (mb_strlen($text) <= $limit * 1.5) {
            return [$text];
        }

        $lines = explode("\n", $text);
        // Title plus a Markdown header and its separator row.
        $headerCount = str_starts_with($lines[0], 'Table:') ? 3 : 2;
        $header = array_slice($lines, 0, $headerCount);
        $pieces = [];
        $current = $header;

        foreach (array_slice($lines, $headerCount) as $line) {
            if (count($current) > $headerCount && mb_strlen(implode("\n", [...$current, $line])) > $limit) {
                $pieces[] = implode("\n", $current);
                $current = $header;
            }
            $current[] = $line;
        }

        $pieces[] = implode("\n", $current);

        return $pieces;
    }

    private function join(string $type, array $sentences): string
    {
        return $type === Element::TEXT ? implode(' ', $sentences) : $sentences[0];
    }

    private function withNotes(string $text, array $endnotes): string
    {
        preg_match_all('/\[\^(\d+)\]/', $text, $m);
        $notes = [];

        foreach (array_unique(array_map('intval', $m[1])) as $number) {
            if (isset($endnotes[$number])) {
                $notes[] = "[^{$number}] {$endnotes[$number]}";
            }
        }

        return $notes ? $text."\n\nNotes:\n".implode("\n", $notes) : $text;
    }
}
