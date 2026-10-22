<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\Message;
use App\Services\Rag\Conversation\ChatAssistant;
use App\Services\Rag\Conversation\ChatHistory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
        ]);

        $conversation = isset($validated['conversation_id'])
            ? $this->findConversation($request, $validated['conversation_id'])
            : null;

        try {
            $result = $assistant->reply($validated['question'], $this->recentHistory($conversation));
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'RAG request failed.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }

        $conversation = $this->saveExchange($request, $conversation, $validated['question'], $result);

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
            'conversation' => $conversation,
            'history' => $conversation?->messages()->get(['role', 'content', 'sources', 'quotes']) ?? [],
        ]);
    }

    // Scoped to the signed-in user, so another user's conversation is a 404, not a leak.
    private function findConversation(Request $request, int $id): Conversation
    {
        return $request->user()->conversations()->findOrFail($id);
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
    private function saveExchange(Request $request, ?Conversation $conversation, string $question, array $result): Conversation
    {
        return DB::transaction(function () use ($request, $conversation, $question, $result) {
            $conversation ??= $request->user()->conversations()->create([
                'title' => Str::limit(Str::squish($question), 80),
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
