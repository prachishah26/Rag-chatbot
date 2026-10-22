<?php

namespace Tests\Unit\Conversation;

use App\Services\Rag\ChatModel;
use App\Services\Rag\Conversation\MessageRouter;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MessageRouterTest extends TestCase
{
    private const HISTORY = [
        ['role' => 'user', 'content' => 'What was GPO revenue in 2018?'],
        ['role' => 'assistant', 'content' => 'GPO revenue in 2018 was $874.5 million.'],
    ];

    private function router(string $reply): MessageRouter
    {
        $chat = Mockery::mock(ChatModel::class);
        $chat->shouldReceive('complete')->once()->andReturn($reply);

        return new MessageRouter($chat);
    }

    public function test_sends_instructions_conversation_and_latest_message(): void
    {
        $chat = Mockery::mock(ChatModel::class);
        $chat->shouldReceive('complete')->once()->withArgs(function (array $messages) {
            return $messages[0]['content'] === MessageRouter::INSTRUCTIONS
                && str_contains($messages[1]['content'], 'User: What was GPO revenue in 2018?')
                && str_contains($messages[1]['content'], 'Assistant: GPO revenue in 2018 was $874.5 million.')
                && str_ends_with($messages[1]['content'], "LATEST MESSAGE:\nwhat about 2024?");
        })->andReturn('{"type": "document", "question": "What was GPO revenue in 2024?"}');

        $routed = (new MessageRouter($chat))->route('what about 2024?', self::HISTORY);

        $this->assertFalse($routed->isChat);
        $this->assertSame('What was GPO revenue in 2024?', $routed->question);
    }

    public function test_first_message_says_there_is_no_conversation(): void
    {
        $chat = Mockery::mock(ChatModel::class);
        $chat->shouldReceive('complete')->once()->withArgs(
            fn (array $messages) => str_starts_with($messages[1]['content'], "CONVERSATION:\n(none)")
        )->andReturn('{"type": "chat"}');

        $this->assertTrue((new MessageRouter($chat))->route('yes please', [])->isChat);
    }

    public function test_reads_json_wrapped_in_a_code_fence(): void
    {
        $routed = $this->router("Sure:\n```json\n{\"type\": \"chat\"}\n```")->route('lol', []);

        $this->assertTrue($routed->isChat);
        $this->assertSame('lol', $routed->question);
    }

    public static function unusableReplies(): array
    {
        return [
            'not json' => ['It is a greeting.'],
            'unknown type' => ['{"type": "smalltalk"}'],
            'empty question' => ['{"type": "document", "question": " "}'],
            'answer instead of question' => ['{"type": "document", "question": "'.str_repeat('Revenue grew a lot. ', 30).'"}'],
        ];
    }

    #[DataProvider('unusableReplies')]
    public function test_unusable_reply_searches_the_original_message(string $reply): void
    {
        $routed = $this->router($reply)->route('what about 2024?', self::HISTORY);

        $this->assertFalse($routed->isChat);
        $this->assertSame('what about 2024?', $routed->question);
    }
}
