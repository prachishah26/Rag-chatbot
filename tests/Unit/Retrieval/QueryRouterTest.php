<?php

namespace Tests\Unit\Retrieval;

use App\Services\Rag\Retrieval\QueryRouter;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class QueryRouterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config([
            'rag.routing.driver' => 'rules',
            'rag.regions' => [
                'South Asia' => ['SAR', 'South Asia', 'India', 'Sri Lanka'],
                'Sub-Saharan Africa' => ['SSA', 'Sub-Saharan Africa', 'South Africa', 'Kenya'],
                'Middle East, North Africa, Afghanistan, and Pakistan' => ['MNA', 'Middle East', 'North Africa', 'Pakistan'],
            ],
            'rag.openai_compat.base_url' => 'https://llm.test/v1',
            'rag.openai_compat.api_key' => 'dummy-key',
            'rag.openai_compat.model' => 'test-model',
            'rag.openai_compat.max_wait' => 0,
        ]);
    }

    private function fakeModel(array $reply): void
    {
        Http::fake(['llm.test/*' => Http::response(['choices' => [['message' => ['content' => json_encode($reply)]]]])]);
    }

    public function test_rules_find_region_by_name_abbreviation_and_country(): void
    {
        $router = new QueryRouter();

        $this->assertSame(['region' => 'South Asia'], $router->route('What is growth in South Asia?'));
        $this->assertSame(['region' => 'South Asia'], $router->route('How fast will SAR grow?'));
        $this->assertSame(['region' => 'South Asia'], $router->route('Inflation in Sri Lanka'));
        $this->assertSame(['region' => 'Middle East, North Africa, Afghanistan, and Pakistan'], $router->route('Debt in Pakistan'));
    }

    public function test_rules_prefer_the_longest_name(): void
    {
        $this->assertSame(['region' => 'Sub-Saharan Africa'], (new QueryRouter())->route('Growth in South Africa'));
    }

    public function test_abbreviation_must_be_upper_case(): void
    {
        $this->assertSame([], (new QueryRouter())->route('What is the sar exchange rate?'));
    }

    public function test_chapter_is_set_only_when_the_question_names_it(): void
    {
        $router = new QueryRouter();

        $this->assertSame(['chapter' => 'Chapter 2'], $router->route('What does chapter 2 say about trade?'));
        $this->assertSame(['chapter' => 'Chapter 3'], $router->route('Summarize Chapter iii'));
        $this->assertSame(['region' => 'South Asia', 'chapter' => 'Chapter 2'], $router->route('Chapter 2 on India'));
        $this->assertSame([], $router->route('What does the report say about trade policy?'));
    }

    public function test_driver_none_returns_no_filter(): void
    {
        config(['rag.routing.driver' => 'none']);

        $this->assertSame([], (new QueryRouter())->route('Chapter 2 on South Asia'));
    }

    public function test_llm_driver_uses_the_model_region(): void
    {
        config(['rag.routing.driver' => 'llm']);
        $this->fakeModel(['region' => 'South Asia']);

        $this->assertSame(['region' => 'South Asia'], (new QueryRouter())->route('How is the subcontinent doing?'));

        Http::assertSent(fn (Request $r) => $r->url() === 'https://llm.test/v1/chat/completions'
            && $r['response_format'] === ['type' => 'json_object']
            && str_contains($r['messages'][0]['content'], '- South Asia (includes: SAR, South Asia, India, Sri Lanka)'));
    }

    public function test_llm_region_outside_the_list_is_dropped(): void
    {
        config(['rag.routing.driver' => 'llm']);
        $this->fakeModel(['region' => 'Atlantis']);

        $this->assertSame([], (new QueryRouter())->route('Growth in Atlantis?'));
    }

    public function test_llm_cannot_add_a_chapter_the_question_does_not_name(): void
    {
        config(['rag.routing.driver' => 'llm']);
        $this->fakeModel(['region' => 'South Asia', 'chapter' => 'Chapter 3']);

        $this->assertSame(['region' => 'South Asia'], (new QueryRouter())->route('Growth in India?'));
    }

    public function test_llm_keeps_a_chapter_the_question_names(): void
    {
        config(['rag.routing.driver' => 'llm']);
        $this->fakeModel(['region' => null, 'chapter' => 'Chapter 3']);

        $this->assertSame(['chapter' => 'Chapter 1'], (new QueryRouter())->route('What is in chapter 1?'));
    }

    public function test_llm_http_failure_falls_back_to_the_rules(): void
    {
        config(['rag.routing.driver' => 'llm']);
        Http::fake(['llm.test/*' => Http::response(['error' => ['message' => 'down']], 500)]);

        $this->assertSame(['region' => 'South Asia', 'chapter' => 'Chapter 2'], (new QueryRouter())->route('Chapter 2: growth in SAR'));
    }

    public function test_llm_non_json_reply_falls_back_to_the_rules(): void
    {
        config(['rag.routing.driver' => 'llm']);
        Http::fake(['llm.test/*' => Http::response(['choices' => [['message' => ['content' => 'South Asia']]]])]);

        $this->assertSame(['region' => 'South Asia'], (new QueryRouter())->route('Growth in India?'));
    }

    public function test_roman_and_arabic_chapter_numbers_give_the_same_filter(): void
    {
        config(['rag.routing.driver' => 'rules']);
        $router = new QueryRouter(new \App\Services\Rag\Indexing\RegionCatalog([]));

        $this->assertSame(['chapter' => 'Chapter 2'], $router->route('What does chapter II say?'));
        $this->assertSame(['chapter' => 'Chapter 4'], $router->route('Summarize Chapter 4'));
        $this->assertSame('Chapter 9', \App\Services\Rag\Indexing\Heading::chapterLabel('IX'));
    }
}
