<?php

namespace App\Services\Rag;

/** Writes an answer to the question from the retrieved document context. */
interface ChatModel
{
    public function chat(string $question, string $context): string;

    /**
     * Sends any prompt (follow-up rewriting, small talk) to the same model.
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     */
    public function complete(array $messages): string;
}
