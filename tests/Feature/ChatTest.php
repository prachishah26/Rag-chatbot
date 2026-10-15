<?php

namespace Tests\Feature;

use App\Services\Rag\RagService;
use Mockery;
use Tests\TestCase;

class ChatTest extends TestCase
{
    public function test_chat_endpoint_returns_rag_answer(): void
    {
        $mock = Mockery::mock(RagService::class);
        $mock->shouldReceive('ask')->once()->with('What is Laravel?')->andReturn([
            'answer' => 'Laravel is a PHP framework.',
            'sources' => [
                ['document' => 'knowledge.txt', 'chunk' => 0, 'score' => 0.91],
            ],
        ]);

        $this->app->instance(RagService::class, $mock);

        $response = $this->postJson(route('chat.ask'), [
            'question' => 'What is Laravel?',
        ]);

        $response->assertOk()
            ->assertJsonPath('answer', 'Laravel is a PHP framework.')
            ->assertJsonCount(1, 'sources');
    }
}
