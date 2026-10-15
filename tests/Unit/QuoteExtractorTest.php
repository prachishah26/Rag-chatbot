<?php

namespace Tests\Unit;

use App\Services\Rag\QuoteExtractor;
use PHPUnit\Framework\TestCase;

class QuoteExtractorTest extends TestCase
{
    private const CHUNKS = [
        [
            'document' => 'gpo.pdf',
            'chunk' => 12,
            'text' => 'FY 2018 Financial Results Revenue totaled $874.5 million while expenses were $821.9 million, '
                .'for an overall net income of $52.6 million from operations. Included in both were costs.',
        ],
        [
            'document' => 'gpo.pdf',
            'chunk' => 46,
            'text' => 'Total assets grew. GPO ended FY 2018 with an increase in retained earnings of $52.6 million '
                .'to $441.9 million, compared with $389.3 million at the end of FY 2017. In comparison, FY 2017 differed.',
        ],
    ];

    public function test_fragment_quote_expands_to_full_original_sentence(): void
    {
        $quotes = (new QuoteExtractor())->ground('"Revenue totaled $874.5 million" [Source 1]', self::CHUNKS);

        $this->assertCount(1, $quotes);
        $this->assertSame(
            'FY 2018 Financial Results Revenue totaled $874.5 million while expenses were $821.9 million, '
                .'for an overall net income of $52.6 million from operations.',
            $quotes[0]['text']
        );
        $this->assertSame(12, $quotes[0]['chunk']);
    }

    public function test_unquoted_verbatim_answer_is_matched_despite_curly_quotes_and_spacing(): void
    {
        $answer = "GPO ended FY 2018 with an increase in retained earnings of  $52.6 million\nto $441.9 million, "
            .'compared with $389.3 million at the end of FY 2017.';

        $quotes = (new QuoteExtractor())->ground($answer, self::CHUNKS);

        $this->assertCount(1, $quotes);
        $this->assertSame(46, $quotes[0]['chunk']);
        $this->assertStringStartsWith('GPO ended FY 2018', $quotes[0]['text']);
    }

    public function test_paraphrase_snaps_to_closest_original_sentence(): void
    {
        $answer = "GPO's retained earnings increased by $52.6 million to $441.9 million in FY 2018.";

        $quotes = (new QuoteExtractor())->ground($answer, self::CHUNKS);

        $this->assertCount(1, $quotes);
        $this->assertStringStartsWith('GPO ended FY 2018 with an increase in retained earnings', $quotes[0]['text']);
    }

    public function test_text_not_in_document_returns_no_quotes(): void
    {
        $quotes = (new QuoteExtractor())->ground('"Paris is the capital of France."', self::CHUNKS);

        $this->assertSame([], $quotes);
    }

    public function test_long_table_run_keeps_only_the_quoted_fragment(): void
    {
        $table = 'Summary (in thousands) 2018 2017 '.str_repeat('Item 1,000 2,000 ', 60)
            .'Net Income $ 52,624 $ 58,876 '.str_repeat('Other 3,000 4,000 ', 60);
        $chunks = [['document' => 'gpo.pdf', 'chunk' => 64, 'text' => $table]];

        $quotes = (new QuoteExtractor())->ground('"Net Income $ 52,624 $ 58,876"', $chunks);

        $this->assertSame('Net Income $ 52,624 $ 58,876', $quotes[0]['text']);
    }

    private function layoutChunk(): array
    {
        return [[
            'document' => 'gpo.pdf',
            'chunk' => 64,
            'text' => file_get_contents(__DIR__.'/../Fixtures/net_income_layout.txt'),
        ]];
    }

    public function test_quote_inside_a_table_returns_the_whole_table(): void
    {
        $quotes = (new QuoteExtractor())->ground('"Net Income $ 52,624 $ 58,876" [Source 1]', $this->layoutChunk());

        $this->assertCount(1, $quotes);
        $this->assertSame(['Net Income', '$ 52,624', '$ 58,876'], $quotes[0]['table'][1]);
        $this->assertStringStartsWith('| Summary of Net Income (in thousands) | 2018 | 2017 |', $quotes[0]['text']);
    }

    public function test_sentence_spanning_lines_excludes_heading_and_is_single_spaced(): void
    {
        $quotes = (new QuoteExtractor())->ground(
            '"GPO’s retained earnings increased by $52.6 million (13.5%)"',
            $this->layoutChunk()
        );

        $this->assertSame(
            'GPO’s retained earnings increased by $52.6 million (13.5%) to $441.9 million at the end of '
                .'fiscal 2018, compared to $389.3 million at the end of fiscal 2017.',
            $quotes[0]['text']
        );
        $this->assertNull($quotes[0]['table']);
    }

    public function test_currency_spacing_differences_still_match(): void
    {
        $quotes = (new QuoteExtractor())->ground('"Net Income $52,624 $58,876"', $this->layoutChunk());

        $this->assertNotEmpty($quotes[0]['table']);
    }

    public function test_table_cut_by_chunk_boundary_has_no_fake_header(): void
    {
        $chunks = [['document' => 'gpo.pdf', 'chunk' => 65, 'text' => "Net Income  $  52,624  $  58,876\nLess: Reserve  (28,745)  (14,783)\n\nMore prose follows here."]];

        $quotes = (new QuoteExtractor())->ground('"Net Income $52,624"', $chunks);

        $this->assertFalse($quotes[0]['table_header']);
        $this->assertStringStartsWith("|  |  |  |\n| --- | --- | --- |\n| Net Income |", $quotes[0]['text']);
    }

    public function test_table_with_header_is_flagged(): void
    {
        $quotes = (new QuoteExtractor())->ground('"Net Income $52,624"', $this->layoutChunk());

        $this->assertTrue($quotes[0]['table_header']);
    }

    public function test_short_numbers_do_not_match_inside_longer_figures(): void
    {
        $quotes = (new QuoteExtractor())->ground('"revenue of $52"', [
            ['document' => 'a.pdf', 'chunk' => 1, 'text' => 'Net revenue of $52,624 was recorded. Other text here.'],
        ]);

        $this->assertSame([], $quotes);
    }

    public function test_tag_only_or_empty_answers_return_nothing(): void
    {
        $this->assertSame([], (new QuoteExtractor())->ground('[Source 1]', self::CHUNKS));
        $this->assertSame([], (new QuoteExtractor())->ground('""', self::CHUNKS));
    }

    public function test_equal_scores_keep_the_higher_ranked_chunk(): void
    {
        $chunks = [
            ['document' => 'a.pdf', 'chunk' => 1, 'text' => 'Revenue rose to 10 million this year.'],
            ['document' => 'a.pdf', 'chunk' => 2, 'text' => 'Revenue grew to 10 million this year.'],
        ];

        $quotes = (new QuoteExtractor())->ground('Revenue increased to 10 million this year', $chunks);

        $this->assertSame(1, $quotes[0]['chunk']);
    }

    public function test_letter_spaced_heading_glued_to_a_sentence_is_dropped(): void
    {
        $chunks = [['document' => 'gpo.pdf', 'chunk' => 43, 'text' => "audit result.\n\nF Y 2 0 1 8 F i n a n c ia l R es ults Revenue totaled\n$874.5 million while expenses were $821.9 million."]];

        $quotes = (new QuoteExtractor())->ground('"Revenue totaled $874.5 million"', $chunks);

        $this->assertSame('Revenue totaled $874.5 million while expenses were $821.9 million.', $quotes[0]['text']);
    }

    public function test_normal_sentences_with_short_words_are_untouched(): void
    {
        $chunks = [['document' => 'a.pdf', 'chunk' => 1, 'text' => 'In FY 2018 we did it. Next.']];

        $quotes = (new QuoteExtractor())->ground('"In FY 2018 we did it"', $chunks);

        $this->assertSame('In FY 2018 we did it.', $quotes[0]['text']);
    }
}
