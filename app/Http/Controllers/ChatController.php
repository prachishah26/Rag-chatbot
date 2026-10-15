<?php

namespace App\Http\Controllers;

use App\Services\Rag\RagService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

class ChatController extends Controller
{
    public function index(): View
    {
        return view('chat');
    }

    public function ask(Request $request, RagService $rag): JsonResponse
    {
        $validated = $request->validate([
            'question' => ['required', 'string', 'max:2000'],
        ]);

        try {
            return response()->json($rag->ask($validated['question']));
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'RAG request failed.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }
}
