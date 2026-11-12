<?php

namespace App\Actions\AI;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use JsonException;
use stdClass;

class GroqProvider
{
    /**
     * @param  list<array{name: string, description: string, parameters: array<string, mixed>}>  $tools
     * @return array<string, mixed>
     */
    public function generate(string $prompt, bool $json = false, array $tools = []): array
    {
        $key = config('services.groq.key');
        $model = config('services.groq.model');

        if (! is_string($key) || trim($key) === '' || ! is_string($model) || trim($model) === '') {
            return ['error' => __('AI provider configuration is incomplete. Please contact support.'), 'status' => 503, 'diagnostics' => ['upstream_status' => null, 'failure_reason' => 'configuration_missing']];
        }

        $payload = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $tools !== [] ? 'Choose exactly one supplied tool. Use only arguments supported by the user request and conversation. Tool calls prepare actions; they never authorize execution.' : ($json ? 'Return only a valid JSON object following the requested fields.' : 'Follow the requested output format.')],
                ['role' => 'user', 'content' => $prompt],
            ],
        ];

        if ($tools !== []) {
            $payload['tools'] = array_map(static fn (array $tool): array => ['type' => 'function', 'function' => $tool], $tools);
            $payload['tool_choice'] = 'required';
            $payload['parallel_tool_calls'] = false;
        } elseif ($json) {
            $payload['response_format'] = ['type' => 'json_object'];
        }

        try {
            $response = Http::withToken($key)
                ->acceptJson()
                ->connectTimeout(3)
                ->timeout(8)
                ->post('https://api.groq.com/openai/v1/chat/completions', $payload);
        } catch (ConnectionException $exception) {
            preg_match('/\bcURL error (\d{1,3})\b/', $exception->getMessage(), $matches);
            $curlError = isset($matches[1]) ? (int) $matches[1] : null;

            return [
                'error' => __('AI services are temporarily unavailable. Please try again later.'),
                'status' => 502,
                'temporary' => in_array($curlError, [6, 7, 28, 52, 55, 56], true),
                'diagnostics' => ['upstream_status' => null, 'failure_reason' => $curlError === 28 ? 'connection_timeout' : 'connection_failure', 'curl_error' => $curlError],
            ];
        }

        if (! $response->successful()) {
            if ($tools !== [] && $response->status() === 400
                && in_array($response->json('error.code'), ['tool_use_failed', 'json_validate_failed'], true)) {
                return [...$this->invalidToolResponse($response->status()), 'diagnostics' => [
                    'upstream_status' => $response->status(), 'failure_reason' => 'invalid_tool_call', 'upstream_error_code' => $response->json('error.code'),
                ]];
            }

            $temporary = in_array($response->status(), [429, 500, 502, 503, 504], true);

            return [
                'error' => $temporary
                    ? __('AI services are temporarily unavailable. Please try again later.')
                    : __('AI provider configuration needs attention. Please contact support.'),
                'status' => 502,
                'temporary' => $temporary && ($response->status() !== 429 || config('services.ai.fallback_on_rate_limit', true)),
                'diagnostics' => ['upstream_status' => $response->status(), 'failure_reason' => 'http_error', 'upstream_error_code' => $response->json('error.code')],
            ];
        }

        $calls = $response->json('choices.0.message.tool_calls');

        if ($tools !== [] && $calls !== null) {
            $call = is_array($calls) && count($calls) === 1 ? ($calls[0]['function'] ?? null) : null;

            if (! is_array($call) || ! in_array($call['name'] ?? null, array_column($tools, 'name'), true)
                || ! is_string($call['arguments'] ?? null)) {
                return $this->invalidToolResponse($response->status());
            }

            try {
                $arguments = json_decode($call['arguments'], false, 32, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                return $this->invalidToolResponse($response->status());
            }

            if (! $arguments instanceof stdClass) {
                return $this->invalidToolResponse($response->status());
            }

            return ['tool_call' => ['name' => $call['name'], 'arguments' => json_decode($call['arguments'], true, 32, JSON_THROW_ON_ERROR)], 'diagnostics' => ['upstream_status' => $response->status()]];
        }

        $text = $response->json('choices.0.message.content');

        if (! is_string($text) || trim($text) === '') {
            return ['error' => __('AI service returned no usable response.'), 'status' => 502, 'retryable_output' => $tools !== [], 'diagnostics' => ['upstream_status' => $response->status(), 'failure_reason' => 'empty_response']];
        }

        if ($json) {
            try {
                $decoded = json_decode($text, false, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                return ['error' => __('AI service returned an invalid response.'), 'status' => 502, 'retryable_output' => $tools !== [], 'diagnostics' => ['upstream_status' => $response->status(), 'failure_reason' => 'invalid_json']];
            }

            if (! $decoded instanceof stdClass) {
                return ['error' => __('AI service returned an invalid response.'), 'status' => 502, 'retryable_output' => $tools !== [], 'diagnostics' => ['upstream_status' => $response->status(), 'failure_reason' => 'invalid_json']];
            }
        }

        return ['text' => $text, 'diagnostics' => ['upstream_status' => $response->status()]];
    }

    /**
     * @return array<string, mixed>
     */
    private function invalidToolResponse(int $status): array
    {
        return ['error' => __('AI service returned an invalid response.'), 'status' => 502, 'retryable_output' => true, 'diagnostics' => ['upstream_status' => $status, 'failure_reason' => 'invalid_tool_call']];
    }
}
