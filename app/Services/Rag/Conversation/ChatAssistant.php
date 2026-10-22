<?php

namespace App\Services\Rag\Conversation;

use App\Services\Rag\ChatModel;
use App\Services\Rag\RagService;
use App\Services\Rag\Retrieval\Retriever;

/** Answers one chat message: small talk directly, document questions through RAG. */
class ChatAssistant
{
    public const SMALL_TALK_INSTRUCTIONS = <<<'PROMPT'
You are the assistant of a document Q&A app: users upload reports and ask questions about them.
Your earlier answers in the conversation came from their documents.
The user's latest message is small talk, not a document question. Often it is a reaction to your
last answer ("perfect", "great", "thanks", "wow", possibly misspelled); then acknowledge it,
e.g. "Glad that helped! Anything else you'd like to know?".

- Reply warmly in one or two short sentences, in plain text.
- Never say you cannot provide something unless the user actually asked for something.
- Use the conversation for personal details the user shared, such as their name. If they ask
  for something they never told you, say so.
- Do not add new facts about documents, companies, figures or the world.
- When it fits, invite them to ask a question about their documents. If they agree without
  saying to what ("yes please", "sure"), ask what they would like to know.
PROMPT;

    public function __construct(
        private RagService $rag,
        private ChatModel $chat,
        private MessageRouter $router,
    ) {}

    /**
     * @param  array<int, array{role: string, content: string}>  $history  earlier messages, oldest first
     * @param  int[]|null  $documentIds  the documents a document question may search; null = whole index.
     *                                    Required, so a caller can never search everyone's documents by omission.
     * @return array{answer: string, quotes: array, sources: array, filter: array}
     */
    public function reply(string $message, array $history, ?array $documentIds): array
    {
        $routed = $this->router->route($message, $history);

        if ($routed->isChat) {
            $answer = $this->chitChat($message, $history);

            // A small-talk reply stating figures the conversation never mentioned answered a factual
            // question from the model's own knowledge ("the iMac costs $1,299"): search instead.
            if (!$this->inventsFigures($answer, $message, $history)) {
                return ['answer' => $answer, 'quotes' => [], 'sources' => [], 'filter' => []];
            }
        }

        return $this->rag->ask($routed->question, Retriever::ADVANCED, $documentIds);
    }

    private function chitChat(string $message, array $history): string
    {
        return $this->chat->complete([
            ['role' => 'system', 'content' => self::SMALL_TALK_INSTRUCTIONS],
            ...$history,
            ['role' => 'user', 'content' => $message],
        ]);
    }

    private function inventsFigures(string $answer, string $message, array $history): bool
    {
        $known = $this->figures(implode(' ', [$message, ...array_column($history, 'content')]));

        return array_diff($this->figures($answer), $known) !== [];
    }

    /** @return string[] numbers, ignoring thousands separators: "$1,299.00" → "1299.00" */
    private function figures(string $text): array
    {
        preg_match_all('/\d[\d,]*(?:\.\d+)?/', $text, $matches);

        return array_unique(array_map(fn (string $n) => str_replace(',', '', $n), $matches[0]));
    }
}
