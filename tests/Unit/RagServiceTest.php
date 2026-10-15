<?php

namespace Tests\Unit;

use App\Services\Rag\ChatModel;
use App\Services\Rag\RagService;
use App\Services\Rag\Retrieval\Passage;
use App\Services\Rag\Retrieval\RetrievalResult;
use App\Services\Rag\Retrieval\Retriever;
use Mockery;
use Tests\TestCase;

class RagServiceTest extends TestCase
{
    private function hit(int $chunk, float $score, string $text): array
    {
        return [
            'score' => $score,
            'payload' => ['document_name' => 'report.pdf', 'chunk_index' => $chunk, 'text' => $text],
        ];
    }

    private function retriever(array $hits): Retriever
    {
        $retriever = Mockery::mock(Retriever::class);
        $retriever->shouldReceive('retrieve')->andReturn(new RetrievalResult(
            $hits,
            array_map(fn (array $hit) => Passage::fromHit($hit, useParent: true), $hits),
            []
        ));

        return $retriever;
    }

    public function test_context_is_capped_to_budget_keeping_highest_ranked_chunks(): void
    {
        config([
            'rag.retrieval.top_k' => 3,
            'rag.retrieval.min_score' => 0.2,
            'rag.retrieval.max_context_chars' => 250,
        ]);

        $chat = Mockery::mock(ChatModel::class);
        $chat->shouldReceive('chat')->once()->withArgs(function (string $q, string $context) {
            return str_contains($context, 'BEST')
                && !str_contains($context, 'WORST')
                && strlen($context) <= 250;
        })->andReturn('"BEST '.str_repeat('a', 100).'" [Source 1]');

        $retriever = $this->retriever([
            $this->hit(1, 0.9, 'BEST '.str_repeat('a', 100)),
            $this->hit(2, 0.8, 'WORST '.str_repeat('b', 200)),
        ]);

        $result = (new RagService($retriever, $chat))->ask('question');

        $this->assertSame('"BEST '.str_repeat('a', 100).'"', $result['answer']);
        $this->assertTrue($result['sources'][0]['cited']);
        $this->assertCount(1, $result['sources']);
        $this->assertSame(1, $result['sources'][0]['chunk']);
    }

    public function test_generated_answer_is_returned_with_supporting_evidence(): void
    {
        config(['rag.retrieval.max_context_chars' => 16000]);

        $chat = Mockery::mock(ChatModel::class);
        $chat->shouldReceive('chat')->andReturn('Retained earnings rose by $52.6 million to $441.9 million in FY 2018 [Source 1].');

        $retriever = $this->retriever([
            $this->hit(46, 0.8, 'Assets grew. GPO ended FY 2018 with an increase in retained earnings of $52.6 million to $441.9 million. Other text.'),
        ]);

        $result = (new RagService($retriever, $chat))->ask('How much did retained earnings increase?');

        $this->assertSame('Retained earnings rose by $52.6 million to $441.9 million in FY 2018.', $result['answer']);
        $this->assertSame('GPO ended FY 2018 with an increase in retained earnings of $52.6 million to $441.9 million.', $result['quotes'][0]['text']);
        $this->assertSame(46, $result['quotes'][0]['chunk']);
        $this->assertTrue($result['sources'][0]['cited']);
    }

    public function test_multi_sentence_answer_collects_evidence_for_each_sentence(): void
    {
        $chat = Mockery::mock(ChatModel::class);
        $chat->shouldReceive('chat')->andReturn(
            'GPO revenue totaled $874.5 million [Source 1]. Net income was $52.6 million for the year [Source 2].'
        );

        $retriever = $this->retriever([
            $this->hit(1, 0.8, 'In FY 2018 GPO revenue totaled $874.5 million.'),
            $this->hit(2, 0.7, 'Net income for the year was $52.6 million.'),
            $this->hit(3, 0.6, 'Unrelated text about printing plants.'),
        ]);

        $result = (new RagService($retriever, $chat))->ask('Revenue and net income?');

        $this->assertSame([1, 2], array_column($result['quotes'], 'chunk'));
        $this->assertSame([true, true, false], array_column($result['sources'], 'cited'));
    }

    public function test_fullwidth_source_brackets_are_stripped_and_counted_as_citations(): void
    {
        // gpt-oss models cite with 【Source 3】; the "3" must not be treated as an ungrounded figure.
        $chat = Mockery::mock(ChatModel::class);
        $chat->shouldReceive('chat')->andReturn('GPO’s total revenue in fiscal year 2018 was $874.5 million【Source 3】.');

        $retriever = $this->retriever([
            $this->hit(1, 0.8, 'Unrelated text about printing plants.'),
            $this->hit(2, 0.7, 'Net income for the year was $52.6 million.'),
            $this->hit(43, 0.6, 'FY 2018 Financial Results Revenue totaled $874.5 million.'),
        ]);

        $result = (new RagService($retriever, $chat))->ask('What was total revenue in FY 2018?');

        $this->assertSame('GPO’s total revenue in fiscal year 2018 was $874.5 million.', $result['answer']);
        $this->assertSame([false, false, true], array_column($result['sources'], 'cited'));
    }

    public function test_figure_rescaled_from_thousands_is_grounded(): void
    {
        // Tables state amounts in thousands; "$1.018 billion" is 1,018,063 thousand rescaled.
        $chat = Mockery::mock(ChatModel::class);
        $chat->shouldReceive('chat')->andReturn('Total assets were $1,018,063 thousand, about $1.018 billion [Source 1].');

        $retriever = $this->retriever([
            $this->hit(47, 0.8, 'Total Assets (in thousands) $ 1,018,063 $ 945,623'),
        ]);

        $result = (new RagService($retriever, $chat))->ask('What were total assets?');

        $this->assertSame('Total assets were $1,018,063 thousand, about $1.018 billion.', $result['answer']);
    }

    public function test_non_breaking_space_before_source_tag_is_removed(): void
    {
        $chat = Mockery::mock(ChatModel::class);
        $chat->shouldReceive('chat')->andReturn("Revenue was \$874.5\u{202F}million\u{00A0}[Source 1].");

        $retriever = $this->retriever([$this->hit(1, 0.8, 'Revenue totaled $874.5 million.')]);

        $result = (new RagService($retriever, $chat))->ask('Revenue?');

        $this->assertSame("Revenue was \$874.5\u{202F}million.", $result['answer']);
    }

    public function test_correction_may_repeat_a_figure_from_the_question(): void
    {
        $chat = Mockery::mock(ChatModel::class);
        $chat->shouldReceive('chat')->andReturn('No, revenue in fiscal 2018 was $874.5 million, not $900 million [Source 1].');

        $retriever = $this->retriever([$this->hit(1, 0.8, 'Revenue totaled $874.5 million.')]);

        $result = (new RagService($retriever, $chat))->ask("GPO's revenue was $900 million in 2018, right?");

        $this->assertSame('No, revenue in fiscal 2018 was $874.5 million, not $900 million.', $result['answer']);
    }

    public function test_difference_of_two_grounded_figures_in_the_answer_is_allowed(): void
    {
        $chat = Mockery::mock(ChatModel::class);
        $chat->shouldReceive('chat')->andReturn(
            'The appropriation fell from $91.6 million in FY 1980 to $79.5 million in FY 2018, a decline of $12.1 million [Source 1].'
        );

        $retriever = $this->retriever([
            $this->hit(15, 0.8, 'In FY 1980 the appropriation was $91.6 million; for FY 2018 it was $79.5 million.'),
        ]);

        $result = (new RagService($retriever, $chat))->ask('How did the appropriation change?');

        $this->assertStringContainsString('a decline of $12.1 million', $result['answer']);
    }

    public function test_figure_that_is_not_a_sum_or_difference_is_still_refused(): void
    {
        $chat = Mockery::mock(ChatModel::class);
        $chat->shouldReceive('chat')->andReturn(
            'The appropriation fell from $91.6 million to $79.5 million, a decline of $15.4 million [Source 1].'
        );

        $retriever = $this->retriever([
            $this->hit(15, 0.8, 'In FY 1980 the appropriation was $91.6 million; for FY 2018 it was $79.5 million.'),
        ]);

        $result = (new RagService($retriever, $chat))->ask('How did the appropriation change?');

        $this->assertSame(RagService::NOT_FOUND, $result['answer']);
    }

    public function test_rescaled_figure_with_wrong_digits_is_refused(): void
    {
        $chat = Mockery::mock(ChatModel::class);
        $chat->shouldReceive('chat')->andReturn('Total assets were about $1.025 billion [Source 1].');

        $retriever = $this->retriever([
            $this->hit(47, 0.8, 'Total Assets (in thousands) $ 1,018,063 $ 945,623'),
        ]);

        $result = (new RagService($retriever, $chat))->ask('What were total assets?');

        $this->assertSame(RagService::NOT_FOUND, $result['answer']);
    }

    public function test_answer_without_matching_evidence_is_still_returned(): void
    {
        $chat = Mockery::mock(ChatModel::class);
        $chat->shouldReceive('chat')->andReturn('The agency modernised its printing operations.');

        $retriever = $this->retriever([$this->hit(1, 0.8, 'GPO revenue totaled $874.5 million.')]);

        $result = (new RagService($retriever, $chat))->ask('What changed?');

        $this->assertSame('The agency modernised its printing operations.', $result['answer']);
        $this->assertSame([], $result['quotes']);
    }

    public function test_answer_with_figure_missing_from_context_is_refused(): void
    {
        $chat = Mockery::mock(ChatModel::class);
        // "35,000" is not in the context; "135,000" must not count as a match.
        $chat->shouldReceive('chat')->andReturn('GPO had 35,000 employees at the end of FY 2018 [Source 1].');

        $retriever = $this->retriever([
            $this->hit(47, 0.8, 'Unexpended appropriations 132,215 135,000 for FY 2018.'),
        ]);

        $result = (new RagService($retriever, $chat))->ask('How many employees did GPO have?');

        $this->assertSame(RagService::NOT_FOUND, $result['answer']);
        $this->assertSame([], $result['quotes']);
    }

    public function test_figures_written_without_thousands_separators_still_match(): void
    {
        $chat = Mockery::mock(ChatModel::class);
        $chat->shouldReceive('chat')->andReturn('GPO had 1737 staff in FY 2018 [Source 1].');

        $retriever = $this->retriever([$this->hit(19, 0.8, 'GPO staff 1,737 FY 2018 revenues $874,466,000')]);

        $result = (new RagService($retriever, $chat))->ask('How many staff?');

        $this->assertSame('GPO had 1737 staff in FY 2018.', $result['answer']);
    }

    public function test_model_refusal_is_not_turned_into_a_quote(): void
    {
        $chat = Mockery::mock(ChatModel::class);
        $chat->shouldReceive('chat')->andReturn('I could not find this in the document.');

        $retriever = $this->retriever([
            $this->hit(1, 0.8, 'Auditors said we could not find this in the uploaded documents archive.'),
        ]);

        $result = (new RagService($retriever, $chat))->ask('Who is the CEO?');

        $this->assertSame(RagService::NOT_FOUND, $result['answer']);
        $this->assertSame([], $result['quotes']);
    }

    public function test_answer_that_only_mentions_could_not_find_is_kept(): void
    {
        $chat = Mockery::mock(ChatModel::class);
        $chat->shouldReceive('chat')->andReturn('Auditors could not find material weaknesses in FY 2018 [Source 1].');
        $retriever = $this->retriever([$this->hit(1, 0.8, 'Auditors could not find material weaknesses in FY 2018.')]);

        $result = (new RagService($retriever, $chat))->ask('Did auditors find weaknesses?');

        $this->assertSame('Auditors could not find material weaknesses in FY 2018.', $result['answer']);
    }
}
