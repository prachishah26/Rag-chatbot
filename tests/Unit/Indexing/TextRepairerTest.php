<?php

namespace Tests\Unit\Indexing;

use App\Services\Rag\Indexing\TextRepairer;
use PHPUnit\Framework\TestCase;

class TextRepairerTest extends TestCase
{
    public function test_ligature_characters_become_plain_letters(): void
    {
        $this->assertSame(
            'The conflict hit fiscal and financial efforts.',
            (new TextRepairer())->repair('The conﬂict hit ﬁscal and ﬁnancial eﬀorts.')
        );
    }

    public function test_dropped_th_ligature_is_restored(): void
    {
        $this->assertSame(
            'The conflict slowed growth. This year, These and They agree. Ten firms stayed.',
            (new TextRepairer())->repair('Te conflict slowed growth. Tis year, Tese and Tey agree. Ten firms stayed.')
        );
    }

    public function test_words_broken_across_lines_are_joined(): void
    {
        $this->assertSame(
            "fiscal sustainability and\nmaintain stability",
            (new TextRepairer())->repair("fiscal sustainabil-\n  ity and\nmaintain stability")
        );
    }

    public function test_real_hyphens_and_dashes_are_kept(): void
    {
        $text = "AI-related trade grew\nin 2025-\n2026 and post-\nCOVID recovery";

        $this->assertSame($text, (new TextRepairer())->repair($text));
    }
}
