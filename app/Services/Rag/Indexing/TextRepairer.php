<?php

namespace App\Services\Rag\Indexing;

/**
 * Fixes damage done by PDF text extraction before anything is chunked or embedded:
 * ligature glyphs ("ﬂ"), a dropped "Th" ligature ("Te conflict") and words hyphenated
 * across lines ("sustainabil- ity"). To a search engine "Te" and "The" are different words.
 */
class TextRepairer
{
    private const LIGATURES = [
        'ﬀ' => 'ff', 'ﬁ' => 'fi', 'ﬂ' => 'fl', 'ﬃ' => 'ffi', 'ﬄ' => 'ffl', 'ﬅ' => 'st', 'ﬆ' => 'st',
    ];

    // Fragments left when a font drops its "Th" glyph; none of them is an English word.
    private const DROPPED_TH = [
        'Te' => 'The', 'Tis' => 'This', 'Tese' => 'These', 'Tey' => 'They', 'Teir' => 'Their',
        'Tose' => 'Those', 'Terefore' => 'Therefore', 'Tus' => 'Thus', 'Tird' => 'Third',
    ];

    private const LINE_BREAK_HYPHEN = '/(\p{L}+)-\n[ \t]*(\p{Ll}+)/u';

    /**
     * Repairs every page, using the whole document as a dictionary for line-break hyphens:
     * "low-\ncarbon" stays "low-carbon" when the document writes it that way elsewhere.
     *
     * @param  array<int, string>  $pages
     * @return array<int, string>
     */
    public function repairPages(array $pages): array
    {
        $pages = array_map(fn (string $text) => $this->restoreDroppedTh(strtr($text, self::LIGATURES)), $pages);
        $hyphenated = $this->hyphenatedWords(implode("\n", $pages));

        return array_map(fn (string $text) => $this->joinBrokenWords($text, $hyphenated), $pages);
    }

    public function repair(string $text): string
    {
        return $this->repairPages([$text])[0];
    }

    private function restoreDroppedTh(string $text): string
    {
        $pattern = '/\b('.implode('|', array_keys(self::DROPPED_TH)).')\b(?=\s+\p{L})/u';

        return preg_replace_callback($pattern, fn (array $m) => self::DROPPED_TH[$m[1]], $text);
    }

    /** "sustainabil-\n ity" → "sustainability"; a capital or digit after the break means a real hyphen. */
    private function joinBrokenWords(string $text, array $hyphenated): string
    {
        return preg_replace_callback(self::LINE_BREAK_HYPHEN, function (array $m) use ($hyphenated) {
            $compound = mb_strtolower($m[1].'-'.$m[2]);

            return isset($hyphenated[$compound]) ? $m[1].'-'.$m[2] : $m[1].$m[2];
        }, $text);
    }

    /** @return array<string, true> hyphenated words written on one line somewhere in the text */
    private function hyphenatedWords(string $text): array
    {
        preg_match_all('/\b\p{L}+-\p{L}+\b/u', $text, $m);

        return array_fill_keys(array_map('mb_strtolower', $m[0]), true);
    }
}
