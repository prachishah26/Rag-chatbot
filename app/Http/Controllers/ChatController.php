<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\Document;
use App\Models\Message;
use App\Models\User;
use App\Services\Rag\Conversation\ChatAssistant;
use App\Services\Rag\Conversation\ChatHistory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class ChatController extends Controller
{
    public function index(Request $request): View
    {
        return $this->chatView($request, null);
    }

    public function show(Request $request, int $conversation): View
    {
        return $this->chatView($request, $this->findConversation($request, $conversation));
    }

    public function ask(Request $request, ChatAssistant $assistant): JsonResponse
    {
        $validated = $request->validate([
            'question' => ['required', 'string', 'max:2000'],
            'conversation_id' => ['nullable', 'integer'],
            'document_id' => ['nullable', 'integer'],
        ]);

        $conversation = isset($validated['conversation_id'])
            ? $this->findConversation($request, $validated['conversation_id'])
            : null;

        // A conversation keeps the document it started with; only a new chat can choose one.
        $document = $conversation
            ? $conversation->document
            : $this->chosenDocument($request->user(), $validated['document_id'] ?? null);

        $documentIds = $document
            ? [$document->id]
            : Document::visibleTo($request->user())->searchable()->orderBy('id')->pluck('id')->all();

        try {
            $result = $assistant->reply($validated['question'], $this->recentHistory($conversation), $documentIds);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'RAG request failed.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }

        $conversation = $this->saveExchange($request, $conversation, $document, $validated['question'], $result);

        return response()->json([...$result, 'conversation_id' => $conversation->id]);
    }

    public function destroy(Request $request, int $conversation): RedirectResponse
    {
        $this->findConversation($request, $conversation)->delete();

        return redirect()->route('chat.index')->with('success', 'Conversation deleted.');
    }

    private function chatView(Request $request, ?Conversation $conversation): View
    {
        return view('chat', [
            'conversations' => $request->user()->conversations()->latest('updated_at')->limit(50)->get(),
            'conversation' => $conversation?->load('document'),
            // Documents a new chat can be limited to.
            'documents' => Document::visibleTo($request->user())->searchable()->orderBy('original_name')->get(['id', 'user_id', 'original_name']),
            'history' => $conversation?->messages()->get(['role', 'content', 'sources', 'quotes']) ?? [],
        ]);
    }

    // Scoped to the signed-in user, so another user's conversation is a 404, not a leak.
    private function findConversation(Request $request, int $id): Conversation
    {
        return $request->user()->conversations()->findOrFail($id);
    }

    private function chosenDocument(User $user, ?int $id): ?Document
    {
        if ($id === null) {
            return null;
        }

        return Document::visibleTo($user)->searchable()->find($id)
            ?? throw ValidationException::withMessages(['document_id' => 'Choose one of your indexed documents.']);
    }

    /** The last few messages, oldest first, so follow-ups and small talk keep their context. */
    private function recentHistory(?Conversation $conversation): array
    {
        $limit = (int) config('rag.chat.history_messages');

        if ($conversation === null || $limit <= 0) {
            return [];
        }

        return ChatHistory::trim(
            $conversation->messages()->reorder()->latest('id')->limit($limit)->get(['role', 'content'])->reverse()
        );
    }

    /** Stores the question and answer together; a failed RAG call stores nothing. */
    private function saveExchange(Request $request, ?Conversation $conversation, ?Document $document, string $question, array $result): Conversation
    {
        return DB::transaction(function () use ($request, $conversation, $document, $question, $result) {
            $conversation ??= $request->user()->conversations()->create([
                'title' => Str::limit(Str::squish($question), 80),
                'document_id' => $document?->id,
            ]);

            $conversation->messages()->create([
                'role' => Message::USER,
                'content' => $question,
            ]);

            $conversation->messages()->create([
                'role' => Message::ASSISTANT,
                'content' => $result['answer'] ?? '',
                'sources' => $result['sources'] ?? [],
                'quotes' => $result['quotes'] ?? [],
            ]);

            return $conversation;
        });
    }
}
