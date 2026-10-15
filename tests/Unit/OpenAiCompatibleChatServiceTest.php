<?php

namespace Tests\Unit;

use App\Services\Rag\ChatModel;
use App\Services\Rag\OllamaService;
use App\Services\Rag\OpenAiCompatibleChatService;
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

    public function test_driver_setting_selects_the_chat_model(): void
    {
        $this->assertInstanceOf(OpenAiCompatibleChatService::class, app(ChatModel::class));

        config(['rag.chat.driver' => 'ollama']);
        $this->app->forgetInstance(ChatModel::class);

        $this->assertInstanceOf(OllamaService::class, app(ChatModel::class));
    }
}
