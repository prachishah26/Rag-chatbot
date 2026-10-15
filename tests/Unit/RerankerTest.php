<?php

namespace Tests\Unit;

use App\Services\Rag\Reranker;
use Tests\TestCase;

class RerankerTest extends TestCase
{
    private function hit(int $chunk, float $score, string $text): array
    {
        return ['score' => $score, 'payload' => ['chunk_index' => $chunk, 'text' => $text]];
    }

    public function test_exact_title_match_outranks_slightly_closer_vector_hits(): void
    {
        config(['rag.retrieval.lexical_weight' => 0.3]);

        $results = [
            $this->hit(78, 0.605, 'Net income from operations rose because revenue held steady.'),
            $this->hit(66, 0.590, 'GPO had net income of approximately $20.4 million in fiscal 2018.'),
            $this->hit(65, 0.527, "Summary of Net Income (in thousands)  2018  2017\nNet Income  $  52,624  $  58,876"),
        ];

        $ranked = app(Reranker::class)->rerank('what is Summary of Net Income', $results);

        $this->assertSame([65, 78, 66], array_map(fn ($r) => $r['payload']['chunk_index'], $ranked));
    }

    public function test_zero_weight_keeps_vector_order(): void
    {
        config(['rag.retrieval.lexical_weight' => 0.0]);

        $results = [
            $this->hit(1, 0.9, 'unrelated text'),
            $this->hit(2, 0.5, 'Summary of Net Income'),
        ];

        $ranked = app(Reranker::class)->rerank('Summary of Net Income', $results);

        $this->assertSame([1, 2], array_map(fn ($r) => $r['payload']['chunk_index'], $ranked));
    }

    public function test_curly_apostrophes_and_case_are_ignored(): void
    {
        config(['rag.retrieval.lexical_weight' => 0.3]);

        $results = [
            $this->hit(1, 0.60, 'Earnings were discussed elsewhere in the report.'),
            $this->hit(2, 0.55, 'GPO’s RETAINED EARNINGS increased by $52.6 million.'),
        ];

        $ranked = app(Reranker::class)->rerank("GPO's retained earnings", $results);

        $this->assertSame(2, $ranked[0]['payload']['chunk_index']);
    }

    public function test_filler_question_words_do_not_count_as_keywords(): void
    {
        config(['rag.retrieval.lexical_weight' => 0.3]);

        $results = [
            $this->hit(19, 0.76, 'GPO staff 1,737'),
            $this->hit(2, 0.75, 'Many agencies have relied on GPO.'),
        ];

        $ranked = app(Reranker::class)->rerank('How many staff did GPO have?', $results);

        $this->assertSame(19, $ranked[0]['payload']['chunk_index']);
    }
}
