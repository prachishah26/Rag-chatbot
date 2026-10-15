<?php

namespace Tests\Unit;

use App\Services\Rag\OpenAiCompatibleClient;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class OpenAiCompatibleClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config([
            'rag.openai_compat.base_url' => 'https://llm.test/v1',
            'rag.openai_compat.api_key' => 'dummy-key',
            'rag.openai_compat.timeout' => 5,
            'rag.openai_compat.max_wait' => 0,
        ]);
    }

    private function client(): OpenAiCompatibleClient
    {
        return new class extends OpenAiCompatibleClient {
            /** @var array<int, int> */
            public array $waits = [];

            protected function wait(int $seconds): void
            {
                $this->waits[] = $seconds;
            }
        };
    }

    private static function answer(string $content): array
    {
        return ['choices' => [['message' => ['content' => $content]]]];
    }

    private static function rateLimited(string $retryAfter): \GuzzleHttp\Promise\PromiseInterface
    {
        return Http::response(['error' => ['message' => 'Rate limit reached']], 429, ['Retry-After' => $retryAfter]);
    }

    public function test_rate_limit_is_waited_out_and_retried(): void
    {
        Http::fake(['llm.test/*' => Http::sequence()
            ->pushResponse(self::rateLimited('2.5'))
            ->pushResponse(self::rateLimited('4'))
            ->push(self::answer('Done.'))]);
        $client = $this->client();

        $this->assertSame('Done.', $client->complete(['model' => 'm'], 'Test', maxWait: 10));
        $this->assertSame([3, 4], $client->waits);
        Http::assertSentCount(3);
    }

    public function test_configured_max_wait_is_used_when_no_argument_is_given(): void
    {
        config(['rag.openai_compat.max_wait' => 5]);
        Http::fake(['llm.test/*' => Http::sequence()
            ->pushResponse(self::rateLimited('5'))
            ->push(self::answer('ok'))]);
        $client = $this->client();

        $this->assertSame('ok', $client->complete(['model' => 'm']));
        $this->assertSame([5], $client->waits);
    }

    public function test_gives_up_when_the_wait_budget_is_exceeded(): void
    {
        Http::fake(['llm.test/*' => Http::sequence()
            ->pushResponse(self::rateLimited('6'))
            ->pushResponse(self::rateLimited('6'))
            ->push(self::answer('never'))]);
        $client = $this->client();

        try {
            $client->complete(['model' => 'm'], 'Test', maxWait: 10);
            $this->fail('Expected a rate limit exception.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('rate limit', $e->getMessage());
        }

        $this->assertSame([6], $client->waits);
        Http::assertSentCount(2);
    }

    public function test_zero_budget_never_waits(): void
    {
        Http::fake(['llm.test/*' => self::rateLimited('1')]);
        $client = $this->client();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Test rate limit reached');

        try {
            $client->complete(['model' => 'm'], 'Test');
        } finally {
            $this->assertSame([], $client->waits);
            Http::assertSentCount(1);
        }
    }

    public function test_attempts_are_capped_even_with_budget_left(): void
    {
        Http::fake(['llm.test/*' => self::rateLimited('1')]);
        $client = $this->client();

        try {
            $client->complete(['model' => 'm'], 'Test', maxWait: 100);
            $this->fail('Expected a rate limit exception.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('rate limit', $e->getMessage());
        }

        $this->assertSame(array_fill(0, 9, 1), $client->waits);
        Http::assertSentCount(10);
    }

    public function test_think_block_is_stripped(): void
    {
        Http::fake(['llm.test/*' => Http::response(self::answer("<think>\nLet me reason about 7.2...\n</think>\n\nGrowth is 6.3 percent."))]);

        $this->assertSame('Growth is 6.3 percent.', $this->client()->complete(['model' => 'm']));
    }

    public function test_missing_api_key_throws_before_any_request(): void
    {
        config(['rag.openai_compat.api_key' => null]);
        Http::fake();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('OPENAI_COMPAT_API_KEY is not set');

        try {
            $this->client()->complete(['model' => 'm']);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_other_http_errors_report_only_the_provider_message(): void
    {
        Http::fake(['llm.test/*' => Http::response(['error' => ['message' => 'Invalid model']], 400)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Test request failed (HTTP 400): Invalid model');

        $this->client()->complete(['model' => 'm'], 'Test');
    }

    public function test_empty_answer_throws(): void
    {
        Http::fake(['llm.test/*' => Http::response(self::answer('   '))]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Test returned an empty answer.');

        $this->client()->complete(['model' => 'm'], 'Test');
    }

    public function test_unclosed_think_block_counts_as_an_empty_answer(): void
    {
        config(['rag.openai_compat.api_key' => 'test-key', 'rag.openai_compat.max_wait' => 0]);
        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response([
            'choices' => [['message' => ['content' => '<think>still reasoning about the passages']]],
        ])]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('empty answer');

        (new \App\Services\Rag\OpenAiCompatibleClient())->complete(['model' => 'm', 'messages' => []]);
    }
}
