<?php

namespace App\Actions\AI;

use App\Models\AiConversation;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AskConversation
{
    private const int MaximumMessages = 12;

    public function id(Request $request, ?string $requested): string
    {
        if ($requested !== null) {
            $existing = \App\Models\AiConversation::query()
                ->find($requested);

            abort_if(
                $existing && $existing->user_id !== $request->user()->id,
                404
            );

            return $requested;
        }
    }

    public function history(Request $request, string $conversationId): array
    {
        $conversation = $request->user()
            ->aiConversations()
            ->find($conversationId);

        if (!$conversation) {
            return [];
        }

        return $conversation->messages()
            ->orderByDesc('id')
            ->limit(self::MaximumMessages)
            ->get()
            ->reverse()
            ->map(fn($message) => [
                'role' => $message->role,
                'content' => Str::limit($message->content, 6000, ''),
            ])
            ->values()
            ->all();
    }

    public function complete(
        Request $request,
        string $conversationId,
        array $reply
    ): array {
        $conversation = $request->user()
            ->aiConversations()
            ->find($conversationId);

        if (!$conversation) {
            return $reply;
        }

        $previous = $conversation->messages()
            ->where('role', 'assistant')
            ->orderByDesc('id')
            ->first();

        $draft = $previous?->metadata['draft'] ?? null;

        if (
            is_array($draft) &&
            ($reply['intent'] ?? null) === 'action' &&
            ($draft['action'] ?? null) === ($reply['action'] ?? null) &&
            is_array($reply['action_data'] ?? null)
        ) {
            $reply['action_data'] = array_replace_recursive(
                $draft['action_data'],
                array_filter(
                    $reply['action_data'],
                    fn($value) => $value !== null
                )
            );
        }

        return $reply;
    }

    public function remember(
        Request $request,
        string $conversationId,
        string $question,
        array $reply
    ): void {
        $user = $request->user();

        $conversation = $user->aiConversations()
            ->firstOrCreate(
                ['id' => $conversationId],
                [
                    'title' => Str::limit(trim($question), 80),
                ]
            );

        $conversation->messages()->create([
            'role' => 'user',
            'content' => $question,
        ]);

        $metadata = [
            'intent' => $reply['intent'] ?? null,
        ];

        if (
            in_array($reply['intent'] ?? null, ['invoice', 'action'], true)
            && isset($reply['confirmation']['token'])
        ) {
            $metadata['proposal'] = [
                'intent' => $reply['intent'],
                'preview' => $reply['preview'] ?? $reply['draft'] ?? [],
                'confirmation' => $reply['confirmation'],
            ];
        }

        if (
            ($reply['intent'] ?? null) === 'action' &&
            isset($reply['errors'], $reply['action_data'])
        ) {
            $metadata['draft'] = [
                'action' => $reply['action'] ?? null,
                'action_data' => $reply['action_data'],
            ];
        }

        $conversation->messages()->create([
            'role' => 'assistant',
            'content' => $this->contextAnswer($reply),
            'metadata' => $metadata,
        ]);

        $conversation->touch();
    }

    public function index(Request $request)
    {
        return response()->json([
            'conversations' => $request->user()
                ->aiConversations()
                ->orderByDesc('updated_at')
                ->get(['id', 'title', 'created_at', 'updated_at']),
        ]);
    }

    public function show(Request $request, string $id)
    {
        $conversation = $request->user()
            ->aiConversations()
            ->findOrFail($id);

        return response()->json([
            'conversation' => $conversation,
            'messages' => $conversation->messages()
                ->orderBy('id')
                ->get(['id', 'role', 'content', 'metadata', 'created_at']),
        ]);
    }

    public function destroy(Request $request, string $id)
    {
        $conversation = $request->user()
            ->aiConversations()
            ->findOrFail($id);

        $conversation->delete();

        return response()->json([
            'message' => 'Conversation deleted successfully.',
        ]);
    }

    private function contextAnswer(array $reply): string
    {
        return match ($reply['intent'] ?? null) {
            'account_read' =>
                'Verified account records were retrieved. '
                . 'Re-query Laravel for current account information. '
                . ($reply['answer'] ?? ''),

            'invoice' =>
                'An invoice review was prepared. '
                . 'No invoice was created by this chat response. '
                . ($reply['answer'] ?? ''),

            'action' =>
                'An account action was proposed or needs clarification. '
                . 'No change was executed. '
                . ($reply['answer'] ?? ''),

            default => (string) ($reply['answer'] ?? ''),
        };
    }
}
