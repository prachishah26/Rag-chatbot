<?php

namespace Tests\Unit\Retrieval;

use App\Services\Rag\Retrieval\Bm25Encoder;
use Tests\TestCase;

class Bm25EncoderTest extends TestCase
{
    private Bm25Encoder $bm25;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bm25 = new Bm25Encoder();
    }

    /** @param  array{indices: array<int, int>, values: array<int, float>}  $vector */
    private function weightOf(array $vector, string $word): ?float
    {
        $position = array_search(crc32($word), $vector['indices'], true);

        return $position === false ? null : $vector['values'][$position];
    }

    public function test_tokens_are_lower_case_without_stop_words(): void
    {
        $this->assertSame(['growth', 'south', 'asia'], $this->bm25->tokens('What is the Growth in SOUTH Asia?'));
    }

    public function test_tokens_keep_decimals_and_strip_thousands_separators(): void
    {
        $tokens = $this->bm25->tokens('Growth of 6.3 percent; GPO had 1,737 employees.');

        $this->assertContains('6.3', $tokens);
        $this->assertContains('1737', $tokens);
        $this->assertNotContains('1,737', $tokens);
        $this->assertNotContains('6', $tokens);
    }

    public function test_tokens_drop_possessive_and_keep_hyphenated_words(): void
    {
        $this->assertSame(['gpo', 'covid-19', 'response'], $this->bm25->tokens("GPO’s COVID-19 response"));
    }

    public function test_repeated_terms_weigh_more_than_single_terms(): void
    {
        $vector = $this->bm25->encodeDocument('inflation inflation inflation growth');

        $this->assertGreaterThan($this->weightOf($vector, 'growth'), $this->weightOf($vector, 'inflation'));
    }

    public function test_same_term_weighs_less_in_a_much_longer_text(): void
    {
        $short = $this->bm25->encodeDocument('inflation rose');
        $long = $this->bm25->encodeDocument('inflation rose '.implode(' ', array_map(fn (int $i) => "word{$i}", range(1, 400))));

        $this->assertLessThan($this->weightOf($short, 'inflation'), $this->weightOf($long, 'inflation'));
    }

    public function test_query_has_weight_one_per_unique_term(): void
    {
        $vector = $this->bm25->encodeQuery('growth growth in South Asia');

        $this->assertCount(3, $vector['indices']);
        $this->assertSame([1.0, 1.0, 1.0], $vector['values']);
    }

    public function test_same_word_maps_to_same_index_in_document_and_query(): void
    {
        $document = $this->bm25->encodeDocument('Remittances to SAR grew 6.3 percent');
        $query = $this->bm25->encodeQuery('SAR remittances');

        $this->assertEmpty(array_diff($query['indices'], $document['indices']));
        $this->assertNotNull($this->weightOf($document, 'sar'));
        $this->assertNotNull($this->weightOf($query, 'sar'));
    }

    public function test_empty_text_gives_empty_vector(): void
    {
        $this->assertSame(['indices' => [], 'values' => []], $this->bm25->encodeQuery('what is the'));
    }
}
