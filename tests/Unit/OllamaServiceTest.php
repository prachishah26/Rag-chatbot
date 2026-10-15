<?php

namespace Tests\Unit;

use App\Services\Rag\OllamaService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OllamaServiceTest extends TestCase
{
    public function test_chat_sets_context_window_and_system_instructions(): void
    {
        config(['rag.ollama.num_ctx' => 8192]);

        Http::fake([
            '*/api/chat' => Http::response(['message' => ['content' => 'Net income was $52.6 million.']]),
        ]);

        $answer = app(OllamaService::class)->chat('What was net income?', 'Net Income $52,624');

        $this->assertSame('Net income was $52.6 million.', $answer);

        Http::assertSent(function (Request $request) {
            $messages = $request['messages'];

            return $request['options']['num_ctx'] === 8192
                && $messages[0]['role'] === 'system'
                && str_contains($messages[0]['content'], 'in your own words')
                && str_contains($messages[0]['content'], 'include ALL of')
                && str_contains($messages[0]['content'], 'open the answer with that total')
                && $messages[1]['role'] === 'user'
                && str_contains($messages[1]['content'], 'Net Income $52,624')
                && str_ends_with(trim($messages[1]['content']), 'What was net income?');
        });
    }
}
