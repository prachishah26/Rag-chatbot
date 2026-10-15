<?php

namespace Tests\Unit\Evaluation;

use App\Services\Rag\Evaluation\EvalQuestion;
use App\Services\Rag\Evaluation\Scorer;
use App\Services\Rag\RagService;
use Tests\TestCase;

class ScorerTest extends TestCase
{
    private Scorer $scorer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scorer = new Scorer();
    }

    private function question(array $evidence = [], array $expect = [], bool $outOfScope = false): EvalQuestion
    {
        return new EvalQuestion('q?', $evidence, $expect, $outOfScope);
    }

    private function hit(string $text, string $document = 'report.pdf'): array
    {
        return ['payload' => ['document_name' => $document, 'text' => $text]];
    }

    public function test_rank_is_the_one_based_position_of_the_first_hit_with_evidence(): void
    {
        $hits = [
            $this->hit('Nothing here.'),
            $this->hit('Growth in SAR is expected to soften to 6.3 percent.'),
            $this->hit('Growth in SAR is expected to soften to 6.3 percent again.'),
        ];

        $this->assertSame(2, $this->scorer->rank($hits, 'report.pdf', $this->question(['soften to 6.3 percent'])));
    }

    public function test_rank_ignores_hits_from_other_documents(): void
    {
        $hits = [$this->hit('874.5 million', 'other.pdf'), $this->hit('noise'), $this->hit('Revenue 874.5 million')];

        $this->assertSame(3, $this->scorer->rank($hits, 'report.pdf', $this->question(['874.5 million'])));
    }

    public function test_rank_is_null_when_no_hit_has_the_evidence(): void
    {
        $this->assertNull($this->scorer->rank([$this->hit('noise')], 'report.pdf', $this->question(['874.5 million'])));
        $this->assertNull($this->scorer->rank([], 'report.pdf', $this->question(['x'])));
    }

    public function test_rank_matches_any_of_several_evidence_phrases(): void
    {
        $this->assertSame(1, $this->scorer->rank([$this->hit('second phrase')], 'report.pdf', $this->question(['first', 'second phrase'])));
    }

    public function test_rank_normalizes_case_whitespace_thousands_and_footnotes(): void
    {
        $hits = [$this->hit("GPO   had\n1,737[^3] EMPLOYEES at year end")];

        $this->assertSame(1, $this->scorer->rank($hits, 'report.pdf', $this->question(['gpo had 1737 employees'])));
        $this->assertSame(1, $this->scorer->rank($hits, 'report.pdf', $this->question(['1,737 employees'])));
    }

    public function test_normalize(): void
    {
        $this->assertSame('net income 1234567 - up', $this->scorer->normalize("  **Net**\tIncome 1,234,567[^12] – UP "));
        $this->assertSame('1.5, 2', $this->scorer->normalize('1.5, 2'));
    }

    public function test_correct_answer_contains_every_expected_fact(): void
    {
        $question = $this->question(['x'], ['445', '99 kg']);

        $this->assertTrue($this->scorer->isCorrect('Baseline was 445 kg; reductions removed 99 KG.', $question));
        $this->assertFalse($this->scorer->isCorrect('Baseline was 445 kg.', $question));
    }

    public function test_numbers_must_stand_alone(): void
    {
        $question = $this->question(['x'], ['4']);

        $this->assertFalse($this->scorer->isCorrect('Growth was 4.4 percent.', $question));
        $this->assertFalse($this->scorer->isCorrect('In 2024 it grew.', $question));
        $this->assertFalse($this->scorer->isCorrect('It grew 14 percent.', $question));
        $this->assertFalse($this->scorer->isCorrect('Version 1.4 shipped.', $question));
        $this->assertTrue($this->scorer->isCorrect('Growth was 4 percent.', $question));
        $this->assertTrue($this->scorer->isCorrect('Growth was 4.', $question));
        $this->assertTrue($this->scorer->isCorrect('Growth was 4%.', $question));
    }

    public function test_numbers_with_thousands_separators_match_either_way(): void
    {
        $this->assertTrue($this->scorer->isCorrect('GPO had 1737 employees.', $this->question(['x'], ['1,737'])));
        $this->assertTrue($this->scorer->isCorrect('GPO had 1,737 employees.', $this->question(['x'], ['1737'])));
    }

    public function test_markdown_bold_is_ignored(): void
    {
        $this->assertTrue($this->scorer->isCorrect('Growth is **6.3** percent in **South Asia**.', $this->question(['x'], ['6.3 percent', 'south asia'])));
    }

    public function test_out_of_scope_is_correct_only_for_the_refusal(): void
    {
        $question = $this->question(outOfScope: true);

        $this->assertTrue($this->scorer->isCorrect(RagService::NOT_FOUND, $question));
        $this->assertFalse($this->scorer->isCorrect('The CEO is Jane Doe.', $question));
    }

    public function test_reciprocal_rank(): void
    {
        $this->assertSame(0.0, $this->scorer->reciprocalRank(null));
        $this->assertSame(1.0, (float) $this->scorer->reciprocalRank(1));
        $this->assertSame(0.25, $this->scorer->reciprocalRank(4));
    }
}
