<?php

namespace Tests\Unit\Retrieval;

use App\Services\Rag\Retrieval\BroadQuestion;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BroadQuestionTest extends TestCase
{
    public static function broadQuestions(): array
    {
        return [
            ['List the important figures and numbers'],
            ['Summarize the report'],
            ['Can you summarise this document please?'],
            ['What are the key findings?'],
            ['Give me the main takeaways'],
            ['What is this document about?'],
            ['Overview'],
            ['tl;dr'],
            ['Summarize chapter 3'],
        ];
    }

    public static function specificQuestions(): array
    {
        return [
            ['What is the global growth forecast for 2026?'],
            ['What were the key figures for revenue in FY 2018?'],
            ['Summarize the outlook for South Asia'],
            ['How many passports did GPO produce?'],
            ['List the regions'],
            [''],
        ];
    }

    #[DataProvider('broadQuestions')]
    public function test_overview_requests_are_broad(string $question): void
    {
        $this->assertTrue((new BroadQuestion())->isBroad($question));
    }

    #[DataProvider('specificQuestions')]
    public function test_questions_naming_a_topic_are_not_broad(string $question): void
    {
        $this->assertFalse((new BroadQuestion())->isBroad($question));
    }

    public function test_summary_headings_are_recognised(): void
    {
        $broad = new BroadQuestion();

        $this->assertTrue($broad->isSummaryHeading('Chapter 1 > GLOBAL OUTLOOK > Summary'));
        $this->assertTrue($broad->isSummaryHeading('Executive Summary'));
        $this->assertTrue($broad->isSummaryHeading('Report > Key findings'));
        $this->assertTrue($broad->isSummaryHeading('Overview'));
        $this->assertFalse($broad->isSummaryHeading('Chapter 2 > REGIONAL OUTLOOKS > South Asia'));
        $this->assertFalse($broad->isSummaryHeading(''));
    }
}
