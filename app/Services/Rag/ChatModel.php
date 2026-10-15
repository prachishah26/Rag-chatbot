<?php

namespace App\Services\Rag;

/** Writes an answer to the question from the retrieved document context. */
interface ChatModel
{
    public function chat(string $question, string $context): string;
}
