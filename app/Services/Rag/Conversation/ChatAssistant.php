<?php

namespace App\Services\Rag\Conversation;

use App\Services\Rag\ChatModel;
use App\Services\Rag\RagService;

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
     * @return array{answer: string, quotes: array, sources: array, filter: array}
     */
    public function reply(string $message, array $history = []): array
    {
        $routed = $this->router->route($message, $history);

        if ($routed->isChat) {
            return $this->chitChat($message, $history);
        }

        return $this->rag->ask($routed->question);
    }

    private function chitChat(string $message, array $history): array
    {
        $answer = $this->chat->complete([
            ['role' => 'system', 'content' => self::SMALL_TALK_INSTRUCTIONS],
            ...$history,
            ['role' => 'user', 'content' => $message],
        ]);

        return ['answer' => $answer, 'quotes' => [], 'sources' => [], 'filter' => []];
    }
}
