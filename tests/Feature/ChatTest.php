<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Document;
use App\Models\Message;
use App\Models\User;
use App\Services\Rag\ChatModel;
use App\Services\Rag\RagService;
use App\Services\Rag\Retrieval\Retriever;
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

    public function test_sidebar_lists_conversations_on_the_documents_page(): void
    {
        $user = User::factory()->create();
        $conversation = $user->conversations()->create(['title' => 'Visible elsewhere']);

        $this->actingAs($user)->get(route('documents.index'))
            ->assertOk()
            ->assertSee('Visible elsewhere')
            ->assertSee(route('chat.destroy', $conversation->id), false);
    }

    public function test_deleting_another_conversation_returns_to_the_current_page(): void
    {
        $user = User::factory()->create();
        $conversation = $user->conversations()->create(['title' => 'Old chat']);

        $this->actingAs($user)
            ->from(route('documents.index'))
            ->delete(route('chat.destroy', $conversation->id))
            ->assertRedirect(route('documents.index'));

        $this->assertModelMissing($conversation);
    }

    public function test_deleting_the_open_conversation_returns_to_a_new_chat(): void
    {
        $user = User::factory()->create();
        $conversation = $user->conversations()->create(['title' => 'Open chat']);

        $this->actingAs($user)
            ->from(route('chat.show', $conversation->id))
            ->delete(route('chat.destroy', $conversation->id))
            ->assertRedirect(route('chat.index'));
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

    /** @param  int[]  $documentIds  the documents the question may be searched in */
    private function mockRag(string $question, array $documentIds = []): void
    {
        $mock = Mockery::mock(RagService::class);
        $mock->shouldReceive('ask')->once()->with($question, Retriever::ADVANCED, $documentIds)->andReturn(self::ANSWER);
        $this->app->instance(RagService::class, $mock);
    }

    public function test_question_searches_own_and_shared_indexed_documents_only(): void
    {
        $user = User::factory()->create();
        $mine = Document::factory()->for($user)->create();
        $shared = Document::factory()->shared()->create();
        Document::factory()->for($user)->status(Document::PROCESSING)->create();
        Document::factory()->create();
        $this->mockRag('What is Laravel?', [$mine->id, $shared->id]);

        $this->actingAs($user)->postJson(route('chat.ask'), ['question' => 'What is Laravel?'])->assertOk();

        $this->assertNull($user->conversations()->sole()->document_id);
    }

    public function test_new_chat_can_be_limited_to_one_document(): void
    {
        $user = User::factory()->create();
        Document::factory()->for($user)->create();
        $chosen = Document::factory()->for($user)->create();
        $this->mockRag('What is Laravel?', [$chosen->id]);

        $this->actingAs($user)->postJson(route('chat.ask'), [
            'question' => 'What is Laravel?',
            'document_id' => $chosen->id,
        ])->assertOk();

        $this->assertSame($chosen->id, $user->conversations()->sole()->document_id);
    }

    public function test_chat_cannot_be_limited_to_another_users_document(): void
    {
        $this->assertDocumentRejected(Document::factory()->create());
    }

    public function test_chat_cannot_be_limited_to_a_document_still_being_indexed(): void
    {
        $user = User::factory()->create();

        $this->assertDocumentRejected(Document::factory()->for($user)->status(Document::QUEUED)->create(), $user);
    }

    public function test_follow_up_keeps_the_conversations_document(): void
    {
        $user = User::factory()->create();
        $chosen = Document::factory()->for($user)->create();
        $other = Document::factory()->for($user)->create();
        $conversation = $user->conversations()->create(['title' => 'Scoped', 'document_id' => $chosen->id]);
        $this->mockRag('And Eloquent?', [$chosen->id]);

        // A conversation's scope is fixed when it starts; a different id in the request is ignored.
        $this->actingAs($user)->postJson(route('chat.ask'), [
            'question' => 'And Eloquent?',
            'conversation_id' => $conversation->id,
            'document_id' => $other->id,
        ])->assertOk();

        $this->assertSame($chosen->id, $conversation->fresh()->document_id);
    }

    public function test_document_picker_offers_only_searchable_documents(): void
    {
        $user = User::factory()->create();
        Document::factory()->for($user)->create(['original_name' => 'ready.pdf']);
        Document::factory()->shared()->create(['original_name' => 'library.pdf']);
        Document::factory()->for($user)->status(Document::FAILED)->create(['original_name' => 'broken.pdf']);
        Document::factory()->create(['original_name' => 'theirs.pdf']);

        $this->actingAs($user)->get(route('chat.index'))
            ->assertOk()
            ->assertSee(['ready.pdf', 'library.pdf'])
            ->assertDontSee(['broken.pdf', 'theirs.pdf']);
    }

    public function test_conversation_page_names_its_document(): void
    {
        $user = User::factory()->create();
        $document = Document::factory()->for($user)->create(['original_name' => 'handbook.pdf']);
        $conversation = $user->conversations()->create(['title' => 'Scoped', 'document_id' => $document->id]);

        $this->actingAs($user)->get(route('chat.show', $conversation->id))
            ->assertOk()
            ->assertSee('handbook.pdf');
    }

    private function assertDocumentRejected(Document $document, ?User $user = null): void
    {
        $rag = Mockery::mock(RagService::class);
        $rag->shouldNotReceive('ask');
        $this->app->instance(RagService::class, $rag);

        $this->actingAs($user ?? User::factory()->create())->postJson(route('chat.ask'), [
            'question' => 'What is Laravel?',
            'document_id' => $document->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('document_id');

        $this->assertDatabaseCount('conversations', 0);
    }

    private function conversationWithHistory(User $user): Conversation
    {
        $conversation = $user->conversations()->create(['title' => 'History']);
        $conversation->messages()->create(['role' => Message::USER, 'content' => 'Remembered question']);
        $conversation->messages()->create(['role' => Message::ASSISTANT, 'content' => 'Remembered answer', 'sources' => [], 'quotes' => []]);

        return $conversation;
    }
}
