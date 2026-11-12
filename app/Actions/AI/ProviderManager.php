<?php

namespace App\Actions\AI;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ProviderManager
{
    public function __construct(private GroqProvider $groq, private GeminiProvider $gemini) {}

    /**
     * @param  list<array{name: string, description: string, parameters: array<string, mixed>}>  $tools
     * @return array<string, mixed>
     */
    public function generate(string $prompt, bool $json = false, array $tools = []): array
    {
        $requestId = (string) Str::uuid();
        $primary = config('services.ai.primary_provider', 'groq');
        $fallback = config('services.ai.fallback_provider', 'gemini');

        if (! in_array($primary, ['groq', 'gemini'], true)
            || ! in_array($fallback, [null, '', 'groq', 'gemini'], true)) {
            Log::warning('AI provider configuration is invalid', [
                'request_id' => $requestId, 'environment' => app()->environment(),
                'failure_reason' => 'invalid_provider', 'fallback_attempted' => false,
            ]);

            return ['error' => __('AI provider configuration is incomplete. Please contact support.'), 'status' => 503];
        }

        $canFallback = in_array($fallback, ['groq', 'gemini'], true) && $fallback !== $primary;
        $result = $this->request($primary, $prompt, $json, $canFallback ? 1 : 3, $tools);
        $reason = $this->diagnostics($result)['failure_reason'];
        $decision = match (true) {
            ! isset($result['error']) => 'not_needed',
            $reason === 'configuration_missing' => 'configuration_missing',
            ! ($result['temporary'] ?? false) => 'permanent_error',
            ! $canFallback => 'not_configured',
            default => 'attempt',
        };
        $this->recordResult($primary, $result, false, $requestId, $decision);

        if ($decision !== 'attempt') {
            if (isset($result['error'])) {
                Log::warning('AI request failed', [
                    'request_id' => $requestId, 'environment' => app()->environment(),
                    'provider' => $primary, 'fallback_attempted' => false,
                    'failure_reason' => $reason, 'fallback_decision' => $decision,
                    'response_status' => $result['status'],
                ]);
            }

            return $this->normalize($result);
        }

        Log::info('AI provider fallback attempted', [
            'request_id' => $requestId, 'environment' => app()->environment(),
            'from_provider' => $primary, 'to_provider' => $fallback, 'reason' => $reason,
        ]);
        $primaryReason = $reason;
        $result = $this->request($fallback, $prompt, $json, 1, $tools);
        $this->recordResult($fallback, $result, true, $requestId, 'already_attempted');

        if (isset($result['error'])) {
            Log::warning('AI request failed', [
                'request_id' => $requestId, 'environment' => app()->environment(),
                'fallback_attempted' => true, 'failure_reason' => 'both_providers_failed',
                'primary_provider' => $primary, 'fallback_provider' => $fallback,
                'primary_failure_reason' => $primaryReason,
                'fallback_failure_reason' => $this->diagnostics($result)['failure_reason'],
                'response_status' => $result['status'],
            ]);

            if ($result['temporary'] ?? false) {
                return ['error' => __('AI services are temporarily unavailable. Please try again later.'), 'status' => 502];
            }
        }

        return $this->normalize($result);
    }

    /**
     * @param  list<array{name: string, description: string, parameters: array<string, mixed>}>  $tools
     * @return array<string, mixed>
     */
    private function request(string $provider, string $prompt, bool $json, int $attempts, array $tools): array
    {
        return $provider === 'groq'
            ? $this->groq->generate($prompt, $json, $tools)
            : $this->gemini->generate($prompt, $json, $attempts, $tools);
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array{upstream_status: int|null, failure_reason: string|null, upstream_error_code: string|null, curl_error: int|null}
     */
    private function diagnostics(array $result): array
    {
        $diagnostics = $result['diagnostics'] ?? [];
        $status = $diagnostics['upstream_status'] ?? null;
        $reason = $diagnostics['failure_reason'] ?? null;
        $errorCode = $diagnostics['upstream_error_code'] ?? null;

        if ($reason === 'http_error') {
            $reason = match ($status) {
                400, 422 => 'invalid_request',
                401, 403 => 'access_denied',
                404 => 'model_unavailable',
                429 => 'rate_limited',
                500, 502, 503, 504 => 'upstream_unavailable',
                default => 'unexpected_http_status',
            };
        }

        return [
            'upstream_status' => is_int($status) ? $status : null,
            'failure_reason' => $reason,
            'upstream_error_code' => in_array($errorCode, [
                'model_not_found', 'model_decommissioned', 'invalid_api_key', 'invalid_request_error',
                'rate_limit_exceeded', 'json_validate_failed', 'tool_use_failed', 'context_length_exceeded',
                'INVALID_ARGUMENT', 'UNAUTHENTICATED', 'PERMISSION_DENIED', 'NOT_FOUND',
                'RESOURCE_EXHAUSTED', 'INTERNAL', 'UNAVAILABLE', 'DEADLINE_EXCEEDED',
            ], true) ? $errorCode : null,
            'curl_error' => $diagnostics['curl_error'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function recordResult(string $provider, array $result, bool $fallback, string $requestId, string $decision): void
    {
        $context = [
            'request_id' => $requestId,
            'environment' => app()->environment(),
            'provider' => $provider,
            'fallback' => $fallback,
            'successful' => ! isset($result['error']),
            'response_status' => $result['status'] ?? 200,
            'fallback_decision' => $decision,
            ...$this->diagnostics($result),
        ];

        if (isset($result['error'])) {
            Log::warning('AI provider request failed', $context);
        } else {
            Log::info('AI provider request completed', $context);
        }
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function normalize(array $result): array
    {
        return array_intersect_key($result, array_flip(['text', 'tool_call', 'error', 'status', 'retryable_output']));
    }
}
