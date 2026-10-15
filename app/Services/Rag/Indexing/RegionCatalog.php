<?php

namespace App\Services\Rag\Indexing;

/** The regions from config('rag.regions'), matched in headings and questions. */
class RegionCatalog
{
    /** @param  array<string, array<int, string>>|null  $regions  region name => aliases */
    public function __construct(private ?array $regions = null) {}

    /** @return array<string, array<int, string>> */
    public function all(): array
    {
        return $this->regions ?? (array) config('rag.regions', []);
    }

    /** The region a heading names exactly ("South Asia"), or null; headings use full names, not aliases. */
    public function forHeading(string $heading): ?string
    {
        $key = $this->normalize($heading);

        foreach ($this->all() as $region => $aliases) {
            if ($key === $this->normalize($region)) {
                return $region;
            }
        }

        return null;
    }

    /**
     * The region a question mentions by name, abbreviation or member country, or null.
     * The longest name wins, so "Middle East and North Africa" beats "Africa".
     */
    public function inQuestion(string $question): ?string
    {
        $best = null;
        $bestLength = 0;

        foreach ($this->all() as $region => $aliases) {
            foreach ([$region, ...$aliases] as $name) {
                // Abbreviations ("SAR") must match in capitals; names match in any case.
                $flags = preg_match('/^[A-Z]{2,6}$/', $name) ? 'u' : 'iu';
                if (mb_strlen($name) > $bestLength && preg_match('/\b'.preg_quote($name, '/').'\b/'.$flags, $question)) {
                    $best = $region;
                    $bestLength = mb_strlen($name);
                }
            }
        }

        return $best;
    }

    private function normalize(string $text): string
    {
        return preg_replace('/[^\p{L}]+/u', '', mb_strtolower($text));
    }
}
