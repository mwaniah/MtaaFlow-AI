<?php

namespace App\Actions\AI;

use App\Models\AiConversation;
use App\Models\AiMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AskConversation
{
    private const int MaximumMessages = 12;

    public function id(Request $request, ?string $requested): string
    {
        if ($requested !== null) {
            $existing = AiConversation::find($requested);

            abort_if(
                $existing &&
                (int) $existing->user_id !== (int) $request->user()->id,
                404
            );

            return $requested;
        }

        return (string) Str::uuid();
    }

    public function history(Request $request, string $conversationId): array
    {
        $conversation = $request->user()
            ->aiConversations()
            ->find($conversationId);

        if (! $conversation) {
            return [];
        }

        if ($conversation->updated_at?->lt(now()->subMinutes(30))) {
            return [];
        }

        $messages = $conversation->messages()
            ->orderByDesc('id')
            ->limit(self::MaximumMessages)
            ->get()
            ->reverse()
            ->values();

        return $messages
            ->filter(function (AiMessage $message, int $index) use ($messages): bool {
                if ($message->role !== 'user') {
                    return true;
                }

                $next = $messages->get($index + 1);

                if (! $next || $next->role !== 'assistant') {
                    return true;
                }

                $nextIntent = $next->metadata['intent'] ?? null;

                return ! in_array($nextIntent, ['account_read', 'invoice', 'action'], true)
                    && ! ($nextIntent === 'conversion' && isset($next->metadata['earnings_conversion_target']));
            })
            ->map(fn (AiMessage $message) => [
                'role' => $message->role,
                'content' => Str::limit($this->contextContent($message), 6000, ''),
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

        if (! $conversation) {
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
                    fn ($value) => $value !== null
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

        if (($reply['intent'] ?? null) === 'account_read' && is_string($reply['query'] ?? null)) {
            $metadata['query'] = $reply['query'];
        }

        $earningsTarget = $reply['earnings_context']['to'] ?? null;

        if (is_string($earningsTarget)) {
            $earningsTarget = strtoupper(trim($earningsTarget));

            if (preg_match('/\A[A-Z]{3}\z/', $earningsTarget) === 1) {
                $metadata['earnings_conversion_target'] = $earningsTarget;
            }
        }

        if (
            in_array($reply['intent'] ?? null, ['invoice', 'action'], true)
            && isset($reply['confirmation']['token'])
        ) {
            $confirmation = $reply['confirmation'];
            unset($confirmation['token'], $confirmation['confirmation_token']);

            $metadata['proposal'] = [
                'intent' => $reply['intent'],
                'preview' => $reply['preview'] ?? $reply['draft'] ?? [],
                'confirmation' => $confirmation,
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
            'content' => (string) ($reply['answer'] ?? $reply['message'] ?? ''),
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
                ->get(['id', 'role', 'content', 'metadata', 'created_at'])
                ->map(function (AiMessage $message): array {
                    $attributes = $message->toArray();
                    $attributes['metadata'] = $this->withoutConfirmationTokens($attributes['metadata'] ?? []);

                    return $attributes;
                }),
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

    private function contextContent(AiMessage $message): string
    {
        if ($message->role !== 'assistant') {
            return $message->content;
        }

        $metadata = $message->metadata ?? [];

        return match ($metadata['intent'] ?? null) {
            'account_read' => 'Previous account query: '.(
                in_array($metadata['query'] ?? null, [
                    'customers',
                    'invoices',
                    'outstanding_invoices',
                    'payments',
                    'revenue',
                    'products',
                    'low_stock',
                    'reminders',
                    'summary',
                ], true)
                    ? $metadata['query']
                    : 'account records'
            ),
            'conversion' => is_string($metadata['earnings_conversion_target'] ?? null)
                ? 'Previous earnings conversion to '.$metadata['earnings_conversion_target'].'.'
                : 'A previous currency conversion was discussed.',
            'invoice' => 'A previous invoice draft was prepared for review.',
            'action' => 'A previous account action was proposed for review.',
            default => $message->content,
        };
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function withoutConfirmationTokens(array $metadata): array
    {
        foreach ($metadata as $key => $value) {
            if (in_array($key, ['token', 'confirmation_token'], true)) {
                unset($metadata[$key]);

                continue;
            }

            if (is_array($value)) {
                $metadata[$key] = $this->withoutConfirmationTokens($value);
            }
        }

        return $metadata;
    }
}
