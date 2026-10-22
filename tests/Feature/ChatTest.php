<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\Rag\ChatModel;
use App\Services\Rag\RagService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class ChatTest extends TestCase
{
    use RefreshDatabase;

    private const ANSWER = [
        'answer' => 'Laravel is a PHP framework.',
        'quotes' => [['document' => 'knowledge.txt', 'chunk' => 0, 'text' => 'Laravel is a PHP framework.']],
        'sources' => [
            ['document' => 'knowledge.txt', 'chunk' => 0, 'score' => 0.91],
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // By default the router passes every message on, unchanged, as a document question.
        $chat = Mockery::mock(ChatModel::class);
        $chat->shouldReceive('complete')->andReturnUsing(fn (array $messages) => json_encode([
            'type' => 'document',
            'question' => Str::after($messages[1]['content'], "LATEST MESSAGE:\n"),
        ]));
        $this->app->instance(ChatModel::class, $chat);
    }

    public function test_chat_endpoint_returns_rag_answer(): void
    {
        $this->mockRag('What is Laravel?');

        $response = $this->actingAs(User::factory()->create())->postJson(route('chat.ask'), [
            'question' => 'What is Laravel?',
        ]);

        $response->assertOk()
            ->assertJsonPath('answer', 'Laravel is a PHP framework.')
            ->assertJsonCount(1, 'sources');
    }

    public function test_first_question_starts_a_saved_conversation(): void
    {
        $user = User::factory()->create();
        $this->mockRag('What is Laravel?');

        $response = $this->actingAs($user)->postJson(route('chat.ask'), ['question' => 'What is Laravel?']);

        $conversation = $user->conversations()->sole();
        $response->assertJsonPath('conversation_id', $conversation->id);
        $this->assertSame('What is Laravel?', $conversation->title);

        $messages = $conversation->messages;
        $this->assertSame([Message::USER, Message::ASSISTANT], $messages->pluck('role')->all());
        $this->assertSame('What is Laravel?', $messages[0]->content);
        $this->assertSame('Laravel is a PHP framework.', $messages[1]->content);
        $this->assertSame(self::ANSWER['sources'], $messages[1]->sources);
        $this->assertSame(self::ANSWER['quotes'], $messages[1]->quotes);
    }

    public function test_follow_up_question_is_added_to_the_same_conversation(): void
    {
        $user = User::factory()->create();
        $conversation = $user->conversations()->create(['title' => 'Laravel']);
        $this->mockRag('And Eloquent?');

        $this->actingAs($user)->postJson(route('chat.ask'), [
            'question' => 'And Eloquent?',
            'conversation_id' => $conversation->id,
        ])->assertOk()->assertJsonPath('conversation_id', $conversation->id);

        $this->assertSame(1, $user->conversations()->count());
        $this->assertSame(2, $conversation->messages()->count());
    }

    public function test_user_cannot_post_into_another_users_conversation(): void
    {
        $other = User::factory()->create()->conversations()->create(['title' => 'Private']);

        $this->actingAs(User::factory()->create())->postJson(route('chat.ask'), [
            'question' => 'Hello?',
            'conversation_id' => $other->id,
        ])->assertNotFound();

        $this->assertSame(0, $other->messages()->count());
    }

    public function test_failed_rag_call_saves_nothing(): void
    {
        $mock = Mockery::mock(RagService::class);
        $mock->shouldReceive('ask')->once()->andThrow(new RuntimeException('Qdrant is down'));
        $this->app->instance(RagService::class, $mock);

        $this->actingAs(User::factory()->create())
            ->postJson(route('chat.ask'), ['question' => 'What is Laravel?'])
            ->assertStatus(500);

        $this->assertDatabaseCount('conversations', 0);
        $this->assertDatabaseCount('messages', 0);
    }

    public function test_conversation_page_shows_previous_messages(): void
    {
        $user = User::factory()->create();
        $conversation = $this->conversationWithHistory($user);

        $this->actingAs($user)->get(route('chat.show', $conversation->id))
            ->assertOk()
            ->assertSee('Remembered question')
            ->assertSee('Remembered answer');
    }

    public function test_sidebar_lists_only_the_users_own_conversations(): void
    {
        $user = User::factory()->create();
        $user->conversations()->create(['title' => 'Mine']);
        User::factory()->create()->conversations()->create(['title' => 'Someone else']);

        $this->actingAs($user)->get(route('chat.index'))
            ->assertOk()
            ->assertSee('Mine')
            ->assertDontSee('Someone else');
    }

    public function test_user_cannot_view_another_users_conversation(): void
    {
        $conversation = $this->conversationWithHistory(User::factory()->create());

        $this->actingAs(User::factory()->create())
            ->get(route('chat.show', $conversation->id))
            ->assertNotFound();
    }

    public function test_user_can_delete_their_conversation(): void
    {
        $user = User::factory()->create();
        $conversation = $this->conversationWithHistory($user);

        $this->actingAs($user)->delete(route('chat.destroy', $conversation->id))
            ->assertRedirect(route('chat.index'));

        $this->assertModelMissing($conversation);
        $this->assertDatabaseCount('messages', 0);
    }

    public function test_user_cannot_delete_another_users_conversation(): void
    {
        $conversation = $this->conversationWithHistory(User::factory()->create());

        $this->actingAs(User::factory()->create())
            ->delete(route('chat.destroy', $conversation->id))
            ->assertNotFound();

        $this->assertModelExists($conversation);
    }

    public function test_small_talk_gets_a_reply_without_a_document_search(): void
    {
        $rag = Mockery::mock(RagService::class);
        $rag->shouldNotReceive('ask');
        $this->app->instance(RagService::class, $rag);

        $chat = Mockery::mock(ChatModel::class);
        $chat->shouldReceive('complete')->twice()->andReturn('{"type": "chat"}', 'Hello! Ask me about your documents.');
        $this->app->instance(ChatModel::class, $chat);

        $user = User::factory()->create();

        $this->actingAs($user)->postJson(route('chat.ask'), ['question' => 'hii'])
            ->assertOk()
            ->assertJsonPath('answer', 'Hello! Ask me about your documents.')
            ->assertJsonCount(0, 'sources');

        $this->assertSame(2, $user->conversations()->sole()->messages()->count());
    }

    public function test_follow_up_is_understood_from_earlier_messages(): void
    {
        $user = User::factory()->create();
        $conversation = $this->conversationWithHistory($user);

        $chat = Mockery::mock(ChatModel::class);
        $chat->shouldReceive('complete')->once()->withArgs(
            fn (array $messages) => str_contains($messages[1]['content'], 'User: Remembered question')
                && str_contains($messages[1]['content'], 'Assistant: Remembered answer')
        )->andReturn('{"type": "document", "question": "What is Laravel?"}');
        $this->app->instance(ChatModel::class, $chat);
        $this->mockRag('What is Laravel?');

        $this->actingAs($user)->postJson(route('chat.ask'), [
            'question' => 'and what is it?',
            'conversation_id' => $conversation->id,
        ])->assertOk()->assertJsonPath('answer', 'Laravel is a PHP framework.');

        // The user's own words are saved, not the rewritten search question.
        $this->assertSame('and what is it?', $conversation->messages()->reorder()->latest('id')->skip(1)->first()->content);
    }

    private function mockRag(string $question): void
    {

        $mock = Mockery::mock(RagService::class);
        $mock->shouldReceive('ask')->once()->with($question)->andReturn(self::ANSWER);
        $this->app->instance(RagService::class, $mock);
    }

    private function conversationWithHistory(User $user): Conversation
    {
        $conversation = $user->conversations()->create(['title' => 'History']);
        $conversation->messages()->create(['role' => Message::USER, 'content' => 'Remembered question']);
        $conversation->messages()->create(['role' => Message::ASSISTANT, 'content' => 'Remembered answer', 'sources' => [], 'quotes' => []]);

        return $conversation;
    }
}
