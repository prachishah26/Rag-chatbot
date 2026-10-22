<?php

namespace App\Services\Rag\Conversation;

use App\Services\Rag\ChatModel;
use Illuminate\Support\Facades\Log;

/**
 * Asks the chat model whether a message is small talk ("hi", "yes please", "what's my name?")
 * or a document question, and rewrites a follow-up ("what about 2024?") as a question that
 * stands on its own ("What was GPO revenue in 2024?"), so search needs no chat history.
 */
class MessageRouter
{
    public const INSTRUCTIONS = <<<'PROMPT'
You route messages in a document Q&A app: users upload reports and ask questions about them.
Decide what the user's latest message is, using the conversation (if any) to understand it.

- "chat": greetings, thanks, goodbyes, short replies and reactions, and questions about you or
  about the user (e.g. their name).
  A reply with no topic of its own ("yes please", "sure", "ok") is "chat" unless the
  assistant's last message offered one specific piece of document information.
- "document": anything that asks for or about information that could be in the documents,
  including a short reply accepting a specific offer ("yes please" after "Would you like the
  revenue table for 2018?"). An open invitation such as "Let me know if there's anything you'd
  like to explore" is not a specific offer.
  A request for facts — prices, figures, dates, people, products, companies, events — is always
  "document", even if you could answer it from general knowledge: answers must come from the
  user's documents. When unsure, choose "document".

For "document", also rewrite the latest message as a standalone search question: fill in what it
leaves out (subject, organisation, metric, year, region) from the conversation, keep every name,
number and year it contains, and leave it unchanged if it already stands on its own. Never answer it.

Reply with JSON only, one of:
{"type": "chat"}
{"type": "document", "question": "<standalone question>"}
PROMPT;

    // A rewrite much longer than the message has likely answered it instead.
    private const MAX_GROWTH_CHARS = 300;

    public function __construct(private ChatModel $chat) {}

    /** @param  array<int, array{role: string, content: string}>  $history  oldest first */
    public function route(string $message, array $history): RoutedMessage
    {
        $reply = $this->chat->complete([
            ['role' => 'system', 'content' => self::INSTRUCTIONS],
            ['role' => 'user', 'content' => $this->prompt($message, $history)],
        ]);

        $decision = $this->decode($reply);

        if ($decision === null) {
            // Searching is the safe default: at worst the user sees "not found".
            Log::warning('Message router returned unreadable output; treating it as a document question.', ['reply' => $reply]);

            return RoutedMessage::document($message);
        }

        if ($decision['type'] === 'chat') {
            return RoutedMessage::chat($message);
        }

        $question = trim((string) ($decision['question'] ?? ''));

        if ($question === '' || mb_strlen($question) > mb_strlen($message) + self::MAX_GROWTH_CHARS) {
            return RoutedMessage::document($message);
        }

        return RoutedMessage::document($question);
    }

    private function prompt(string $message, array $history): string
    {
        $conversation = $history === [] ? '(none)' : ChatHistory::transcript($history);

        return "CONVERSATION:\n{$conversation}\n\nLATEST MESSAGE:\n{$message}";
    }

    /** The first JSON object in the reply, tolerating code fences or a sentence around it. */
    private function decode(string $reply): ?array
    {
        if (!preg_match('/\{.*\}/s', $reply, $match)) {
            return null;
        }

        $decision = json_decode($match[0], true);

        return is_array($decision) && in_array($decision['type'] ?? null, ['chat', 'document'], true)
            ? $decision
            : null;
    }
}
