<?php

namespace App\Actions\AI;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AskConversation
{
    private const string SessionKey = 'ask_mtaaflow';

    private const int MaximumMessages = 12;

    private const int MaximumCharacters = 24000;

    private const int MaximumConversations = 5;

    public function id(Request $request, ?string $requested): string
    {
        $state = $this->state($request);

        return $requested ?? ($state['active'] ?? (string) Str::uuid());
    }

    /**
     * @return list<array{role: string, content: string}>
     */
    public function history(Request $request, string $conversationId): array
    {
        return $this->state($request)['threads'][$conversationId]['messages'] ?? [];
    }

    /**
     * @param  array<string, mixed>  $reply
     * @return array<string, mixed>
     */
    public function complete(Request $request, string $conversationId, array $reply): array
    {
        $draft = $this->state($request)['threads'][$conversationId]['draft'] ?? null;
        if (is_array($draft) && ($reply['intent'] ?? null) === 'action'
            && ($draft['action'] ?? null) === ($reply['action'] ?? null)
            && is_array($reply['action_data'] ?? null)) {
            $reply['action_data'] = array_replace_recursive($draft['action_data'], array_filter($reply['action_data'], fn (mixed $value): bool => $value !== null));
        }

        return $reply;
    }

    /**
     * @param  array<string, mixed>  $reply
     */
    public function remember(Request $request, string $conversationId, string $question, array $reply): void
    {
        $state = $this->state($request);
        $messages = $state['threads'][$conversationId]['messages'] ?? [];
        $messages[] = ['role' => 'user', 'content' => $this->contextText($question)];
        $messages[] = ['role' => 'assistant', 'content' => $this->contextAnswer($reply)];
        $messages = array_slice($messages, -self::MaximumMessages);

        while (array_sum(array_map(fn (array $message): int => mb_strlen($message['content']), $messages)) > self::MaximumCharacters) {
            $messages = array_slice($messages, 2);
        }

        unset($state['threads'][$conversationId]);
        $state['threads'][$conversationId] = ['messages' => $messages, 'updated_at' => now()->timestamp];
        if (($reply['intent'] ?? null) === 'action' && isset($reply['errors'], $reply['action_data'])) {
            $state['threads'][$conversationId]['draft'] = array_intersect_key($reply, array_flip(['action', 'action_data']));
        }
        $state['active'] = $conversationId;

        if (count($state['threads']) > self::MaximumConversations) {
            $state['threads'] = array_slice($state['threads'], -self::MaximumConversations, null, true);
        }

        $request->session()->put(self::SessionKey, $state);
    }

    /**
     * @return array{user_id: int, active?: string, threads: array<string, array{messages: list<array{role: string, content: string}>, updated_at: int}>}
     */
    private function state(Request $request): array
    {
        $state = $request->session()->get(self::SessionKey, []);
        $userId = $request->user()->id;

        if (($state['user_id'] ?? null) !== $userId) {
            return ['user_id' => $userId, 'threads' => []];
        }

        $cutoff = now()->subMinutes(30)->timestamp;
        $state['threads'] = array_filter($state['threads'], fn (array $thread): bool => $thread['updated_at'] >= $cutoff);

        return $state;
    }

    /**
     * @param  array<string, mixed>  $reply
     */
    private function contextAnswer(array $reply): string
    {
        return match ($reply['intent'] ?? null) {
            'account_read' => 'Verified account records were retrieved for query '.($reply['query'] ?? 'summary').'. Re-query Laravel for current counts, names, amounts or dates; do not treat this as stored account facts.',
            'invoice' => 'An invoice review was prepared. No invoice was created or approved by this chat response.',
            'action' => 'An account action was proposed or needs clarification. No change was executed. '.json_encode(array_intersect_key($reply, array_flip(['action', 'action_data', 'preview', 'errors', 'answer'])), JSON_THROW_ON_ERROR),
            'conversion' => isset($reply['earnings_context'])
                ? 'An earnings conversion was requested with context '.json_encode($reply['earnings_context'], JSON_THROW_ON_ERROR).'. Re-query Laravel before further account conversions; no earnings amount is stored in this context.'
                : 'A server-side conversion was answered. Re-query verified earnings before any further account conversion. '.$this->contextText($reply['answer'] ?? ''),
            default => $this->contextText($reply['answer'] ?? ''),
        };
    }

    private function contextText(string $text): string
    {
        return Str::limit($text, 6000, '');
    }
}
