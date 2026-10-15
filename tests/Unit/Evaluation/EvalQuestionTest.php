<?php

namespace Tests\Unit\Evaluation;

use App\Services\Rag\Evaluation\EvalQuestion;
use InvalidArgumentException;
use Tests\TestCase;

class EvalQuestionTest extends TestCase
{
    public function test_builds_from_array_and_trims_question(): void
    {
        $question = EvalQuestion::fromArray(['question' => '  Revenue? ', 'evidence' => ['874.5 million'], 'expect' => ['874.5']], 'q1');

        $this->assertSame('Revenue?', $question->question);
        $this->assertSame(['874.5 million'], $question->evidence);
        $this->assertSame(['874.5'], $question->expect);
        $this->assertFalse($question->outOfScope);
    }

    public function test_single_strings_become_lists_and_non_strings_are_dropped(): void
    {
        $question = EvalQuestion::fromArray(['question' => 'Q', 'evidence' => 'phrase', 'expect' => ['1', 2, null]], 'q1');

        $this->assertSame(['phrase'], $question->evidence);
        $this->assertSame(['1'], $question->expect);
    }

    public function test_out_of_scope_needs_no_evidence_or_expect(): void
    {
        $question = EvalQuestion::fromArray(['question' => 'Who is the CEO?', 'out_of_scope' => true], 'q1');

        $this->assertTrue($question->outOfScope);
        $this->assertSame([], $question->evidence);
    }

    public static function invalidQuestions(): array
    {
        return [
            'not an array' => ['text'],
            'no question' => [['evidence' => ['a'], 'expect' => ['b']]],
            'blank question' => [['question' => '  ', 'evidence' => ['a'], 'expect' => ['b']]],
            'no evidence' => [['question' => 'Q', 'expect' => ['b']]],
            'no expect' => [['question' => 'Q', 'evidence' => ['a']]],
            'empty expect' => [['question' => 'Q', 'evidence' => ['a'], 'expect' => []]],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidQuestions')]
    public function test_invalid_question_is_rejected(mixed $data): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('set.json question 3');

        EvalQuestion::fromArray($data, 'set.json question 3');
    }
}
