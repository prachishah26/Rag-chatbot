<?php

namespace Tests\Unit\Indexing;

use App\Services\Rag\Indexing\SentenceSplitter;
use PHPUnit\Framework\TestCase;

class SentenceSplitterTest extends TestCase
{
    public function test_it_splits_on_sentence_ending_punctuation(): void
    {
        $this->assertSame(
            ['Growth slowed.', 'Prices rose!', 'Will it last?', 'Nobody knows.'],
            (new SentenceSplitter())->split('Growth slowed. Prices rose! Will it last? Nobody knows.')
        );
    }

    public function test_it_does_not_split_after_listed_abbreviations(): void
    {
        $this->assertSame(
            ['The U.S. Treasury raised rates, e.g. China followed.', 'Others waited.'],
            (new SentenceSplitter())->split('The U.S. Treasury raised rates, e.g. China followed. Others waited.')
        );
    }

    public function test_it_does_not_split_after_a_single_initial(): void
    {
        $this->assertSame(
            ['John W. Crawford wrote the report.', 'It was long.'],
            (new SentenceSplitter())->split('John W. Crawford wrote the report. It was long.')
        );
    }

    public function test_a_footnote_marker_stays_with_the_sentence_it_ends(): void
    {
        $this->assertSame(
            ['Emissions fell 20 percent.[^7]', 'Targets were met.'],
            (new SentenceSplitter())->split('Emissions fell 20 percent.[^7] Targets were met.')
        );
    }

    public function test_blank_lines_split_paragraphs_and_wrapped_lines_are_joined(): void
    {
        $this->assertSame(
            ['First paragraph without a period', 'Second paragraph wraps over lines.'],
            (new SentenceSplitter())->split("First paragraph without a period\n\n  \nSecond paragraph\nwraps over lines.")
        );
    }

    public function test_empty_text_has_no_sentences(): void
    {
        $this->assertSame([], (new SentenceSplitter())->split("  \n\n  "));
    }

    public function test_it_does_not_split_before_a_lowercase_word(): void
    {
        $this->assertSame(
            ['Revenue reached 3.5 bn. in total and grew.'],
            (new SentenceSplitter())->split('Revenue reached 3.5 bn. in total and grew.')
        );
    }
}
