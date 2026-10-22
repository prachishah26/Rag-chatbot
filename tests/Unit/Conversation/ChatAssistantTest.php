<?php

namespace Tests\Unit\Conversation;

use App\Services\Rag\ChatModel;
use App\Services\Rag\Conversation\ChatAssistant;
use App\Services\Rag\Conversation\ChatHistory;
use App\Services\Rag\Conversation\MessageRouter;
use App\Services\Rag\Conversation\RoutedMessage;
use App\Services\Rag\RagService;
use App\Services\Rag\Retrieval\Retriever;
use Mockery;
use Tests\TestCase;

class ChatAssistantTest extends TestCase
{
    public function test_small_talk_is_answered_from_the_conversation_without_searching(): void
    {
        $history = [
            ['role' => 'user', 'content' => 'my name is prachi'],
            ['role' => 'assistant', 'content' => 'Nice to meet you, Prachi!'],
        ];

        $rag = Mockery::mock(RagService::class);
        $rag->shouldNotReceive('ask');

        $chat = Mockery::mock(ChatModel::class);
        $chat->shouldReceive('complete')->once()->withArgs(function (array $messages) use ($history) {
            return $messages[0]['content'] === ChatAssistant::SMALL_TALK_INSTRUCTIONS
                && array_slice($messages, 1, 2) === $history
                && $messages[3] === ['role' => 'user', 'content' => "what's my name?"];
        })->andReturn('Your name is Prachi.');

        $router = Mockery::mock(MessageRouter::class);
        $router->shouldReceive('route')->once()->with("what's my name?", $history)->andReturn(RoutedMessage::chat("what's my name?"));

        $result = (new ChatAssistant($rag, $chat, $router))->reply("what's my name?", $history, null);

        $this->assertSame(['answer' => 'Your name is Prachi.', 'quotes' => [], 'sources' => [], 'filter' => []], $result);
    }

    public function test_small_talk_reply_with_unknown_figures_is_replaced_by_a_document_search(): void
    {
        $router = Mockery::mock(MessageRouter::class);
        $router->shouldReceive('route')->andReturn(RoutedMessage::chat('How much does the iMac cost?'));

        // Misrouted as small talk, the model answers from its own knowledge: never show that.
        $chat = Mockery::mock(ChatModel::class);
        $chat->shouldReceive('complete')->once()->andReturn('The iMac starts at about $1,299 for the 24-inch model.');

        $rag = Mockery::mock(RagService::class);
        $rag->shouldReceive('ask')->once()->with('How much does the iMac cost?', Retriever::ADVANCED, [3])
            ->andReturn(['answer' => RagService::NOT_FOUND]);

        $result = (new ChatAssistant($rag, $chat, $router))->reply('How much does the iMac cost?', [], [3]);

        $this->assertSame(RagService::NOT_FOUND, $result['answer']);
    }

    public function test_small_talk_reply_may_repeat_figures_from_the_conversation(): void
    {
        $history = [['role' => 'user', 'content' => 'I am 30 and live at number 221.']];
        $router = Mockery::mock(MessageRouter::class);
        $router->shouldReceive('route')->andReturn(RoutedMessage::chat('how old am I?'));

        $chat = Mockery::mock(ChatModel::class);
        $chat->shouldReceive('complete')->once()->andReturn('You told me you are 30.');

        $rag = Mockery::mock(RagService::class);
        $rag->shouldNotReceive('ask');

        $result = (new ChatAssistant($rag, $chat, $router))->reply('how old am I?', $history, null);

        $this->assertSame('You told me you are 30.', $result['answer']);
    }

    public function test_document_question_is_searched_as_a_standalone_question(): void
    {
        $history = [['role' => 'user', 'content' => 'What was GPO revenue in 2018?']];

        $router = Mockery::mock(MessageRouter::class);
        $router->shouldReceive('route')->once()->with('what about 2024?', $history)
            ->andReturn(RoutedMessage::document('What was GPO revenue in 2024?'));

        $rag = Mockery::mock(RagService::class);
        $rag->shouldReceive('ask')->once()->with('What was GPO revenue in 2024?', Retriever::ADVANCED, null)->andReturn(['answer' => 'It was $1 million.']);

        $result = (new ChatAssistant($rag, Mockery::mock(ChatModel::class), $router))->reply('what about 2024?', $history, null);

        $this->assertSame('It was $1 million.', $result['answer']);
    }

    public function test_document_question_is_searched_within_the_given_documents(): void
    {
        $router = Mockery::mock(MessageRouter::class);
        $router->shouldReceive('route')->andReturn(RoutedMessage::document('What is the deadline?'));

        $rag = Mockery::mock(RagService::class);
        $rag->shouldReceive('ask')->once()->with('What is the deadline?', Retriever::ADVANCED, [5])->andReturn(['answer' => 'Friday.']);

        $result = (new ChatAssistant($rag, Mockery::mock(ChatModel::class), $router))->reply('deadline?', [], [5]);

        $this->assertSame('Friday.', $result['answer']);
    }

    public function test_history_is_trimmed_to_user_and_assistant_messages(): void
    {
        $history = ChatHistory::trim([
            ['role' => 'user', 'content' => 'hi'],
            ['role' => 'system', 'content' => str_repeat('a', 2000)],
        ]);

        $this->assertSame(['role' => 'user', 'content' => 'hi'], $history[0]);
        $this->assertSame('assistant', $history[1]['role']);
        $this->assertSame(800, mb_strwidth($history[1]['content']));
    }
}
