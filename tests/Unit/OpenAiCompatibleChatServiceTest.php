<?php

namespace Tests\Unit;

use App\Services\Rag\ChatModel;
use App\Services\Rag\OllamaService;
use App\Services\Rag\OpenAiCompatibleChatService;
use App\Services\Rag\RateLimitException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class OpenAiCompatibleChatServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'rag.chat.driver' => 'openai',
            'rag.openai_compat.base_url' => 'https://api.groq.com/openai/v1',
            'rag.openai_compat.api_key' => 'test-key',
            'rag.openai_compat.model' => 'llama-3.3-70b-versatile',
            'rag.openai_compat.fallback_models' => [],
        ]);
    }

    public function test_chat_sends_prompt_with_bearer_key_and_returns_answer(): void
    {
        Http::fake([
            'api.groq.com/*' => Http::response(['choices' => [['message' => ['content' => ' Net income was $52.6 million. ']]]]),
        ]);

        $answer = app(OpenAiCompatibleChatService::class)->chat('What was net income?', 'Net Income $52,624');

        $this->assertSame('Net income was $52.6 million.', $answer);

        Http::assertSent(function (Request $request) {
            $messages = $request['messages'];

            return $request->url() === 'https://api.groq.com/openai/v1/chat/completions'
                && $request->hasHeader('Authorization', 'Bearer test-key')
                && $request['model'] === 'llama-3.3-70b-versatile'
                && $request['temperature'] === 0
                && $messages[0]['role'] === 'system'
                && str_contains($messages[0]['content'], 'include ALL of')
                && str_ends_with(trim($messages[1]['content']), 'What was net income?');
        });
    }

    public function test_missing_api_key_fails_before_any_request(): void
    {
        config(['rag.openai_compat.api_key' => '']);
        Http::fake();

        $this->expectExceptionMessage('OPENAI_COMPAT_API_KEY is not set');

        try {
            app(OpenAiCompatibleChatService::class)->chat('q', 'context');
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_rate_limit_error_is_reported_clearly(): void
    {
        config(['rag.openai_compat.max_wait' => 0]);
        Http::fake(['*' => Http::response(['error' => ['message' => 'Rate limit reached']], 429)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('rate limit');

        app(OpenAiCompatibleChatService::class)->chat('q', 'context');
    }

    /** Answers with the model name, except for the given rate-limited models. */
    private static function fakeModels(array $rateLimited, array $failing = []): void
    {
        Http::fake(['*' => function (Request $request) use ($rateLimited, $failing) {
            if (in_array($request['model'], $rateLimited, true)) {
                return Http::response(['error' => ['message' => "Rate limit reached for {$request['model']}"]], 429);
            }

            if (in_array($request['model'], $failing, true)) {
                return Http::response(['error' => ['message' => 'Server error']], 500);
            }

            return Http::response(['choices' => [['message' => ['content' => "Answer from {$request['model']}"]]]]);
        }]);
    }

    public function test_rate_limited_model_falls_back_to_the_next_model(): void
    {
        config([
            'rag.openai_compat.max_wait' => 0,
            'rag.openai_compat.fallback_models' => ['openai/gpt-oss-20b', 'qwen/qwen3-32b'],
        ]);
        self::fakeModels(['llama-3.3-70b-versatile', 'openai/gpt-oss-20b']);

        $answer = app(OpenAiCompatibleChatService::class)->chat('q', 'context');

        $this->assertSame('Answer from qwen/qwen3-32b', $answer);
        Http::assertSentCount(3);
    }

    public function test_primary_model_is_used_when_it_is_not_rate_limited(): void
    {
        config(['rag.openai_compat.fallback_models' => ['openai/gpt-oss-20b']]);
        self::fakeModels([]);

        $this->assertSame('Answer from llama-3.3-70b-versatile', app(OpenAiCompatibleChatService::class)->chat('q', 'context'));
        Http::assertSentCount(1);
    }

    public function test_last_rate_limit_is_reported_when_every_model_is_limited(): void
    {
        config([
            'rag.openai_compat.max_wait' => 0,
            'rag.openai_compat.fallback_models' => ['openai/gpt-oss-20b'],
        ]);
        self::fakeModels(['llama-3.3-70b-versatile', 'openai/gpt-oss-20b']);

        try {
            app(OpenAiCompatibleChatService::class)->chat('q', 'context');
            $this->fail('Expected a rate limit exception.');
        } catch (RateLimitException $e) {
            $this->assertStringContainsString('openai/gpt-oss-20b', $e->getMessage());
        }

        Http::assertSentCount(2);
    }

    public function test_other_errors_do_not_fall_back(): void
    {
        config(['rag.openai_compat.fallback_models' => ['openai/gpt-oss-20b']]);
        self::fakeModels([], failing: ['llama-3.3-70b-versatile']);

        $this->expectExceptionMessage('HTTP 500');

        try {
            app(OpenAiCompatibleChatService::class)->chat('q', 'context');
        } finally {
            Http::assertSentCount(1);
        }
    }

    public function test_primary_model_listed_as_fallback_is_not_retried(): void
    {
        config([
            'rag.openai_compat.max_wait' => 0,
            'rag.openai_compat.fallback_models' => ['llama-3.3-70b-versatile', 'openai/gpt-oss-20b'],
        ]);
        self::fakeModels(['llama-3.3-70b-versatile']);

        $this->assertSame('Answer from openai/gpt-oss-20b', app(OpenAiCompatibleChatService::class)->chat('q', 'context'));
        Http::assertSentCount(2);
    }

    public function test_driver_setting_selects_the_chat_model(): void
    {
        $this->assertInstanceOf(OpenAiCompatibleChatService::class, app(ChatModel::class));

        config(['rag.chat.driver' => 'ollama']);
        $this->app->forgetInstance(ChatModel::class);

        $this->assertInstanceOf(OllamaService::class, app(ChatModel::class));
    }
}
