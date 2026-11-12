<?php

namespace App\Actions\AI;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class GeminiProvider
{
    /**
     * @param  list<array{name: string, description: string, parameters: array<string, mixed>}>  $tools
     * @return array<string, mixed>
     */
    public function generate(string $prompt, bool $json = false, int $attempts = 3, array $tools = []): array
    {
        $key = config('services.gemini.key');
        $model = config('services.gemini.model');

        if (! is_string($key) || trim($key) === '' || ! is_string($model) || trim($model) === '') {
            return [
                'error' => __('AI integration is not configured yet.'),
                'diagnostics' => ['upstream_status' => null, 'failure_reason' => 'configuration_missing'],
                'status' => 503,
            ];
        }

        try {
            $payload = [
                'contents' => [
                    [
                        'role' => 'user',
                        'parts' => [
                            ['text' => $prompt],
                        ],
                    ],
                ],
            ];

            if ($tools !== []) {
                $payload['tools'] = [['functionDeclarations' => array_map(static fn (array $tool): array => [
                    'name' => $tool['name'],
                    'description' => $tool['description'],
                    'parametersJsonSchema' => $tool['parameters'],
                ], $tools)]];
                $payload['toolConfig'] = ['functionCallingConfig' => ['mode' => 'ANY', 'allowedFunctionNames' => array_column($tools, 'name')]];
            } elseif ($json) {
                $payload['generationConfig'] = [
                    'responseMimeType' => 'application/json',
                ];
            }

            $response = Http::connectTimeout(3)
                ->timeout(8)
                ->retry(
                    times: $attempts,
                    sleepMilliseconds: static fn (int $attempt): int => 500 * (2 ** ($attempt - 1)) + random_int(0, 250),
                    when: static function (Throwable $exception): bool {
                        if ($exception instanceof RequestException) {
                            return $exception->response->status() === 503;
                        }

                        if (! $exception instanceof ConnectionException) {
                            return false;
                        }

                        return preg_match('/\bcURL error 28\b/', $exception->getMessage()) === 1;
                    },
                    throw: false,
                )
                ->withHeaders([
                    'x-goog-api-key' => $key,
                ])
                ->post(
                    "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent",
                    $payload
                );

            if ($response->failed()) {
                Log::warning('Gemini API request failed', [
                    'status' => $response->status(),
                ]);

                return [
                    'error' => match ($response->status()) {
                        400 => __('Gemini rejected the request. Check the configured model and request options.'),
                        401, 403 => __('Gemini access was rejected. Check the API key and project permissions.'),
                        404 => __('The configured Gemini model is unavailable. Check GEMINI_MODEL for a supported model.'),
                        429 => __('Gemini quota or rate limit exceeded. Check your project quota and try again later.'),
                        default => __('AI service is temporarily unavailable.'),
                    },
                    'status' => 502,
                    'diagnostics' => ['upstream_status' => $response->status(), 'failure_reason' => 'http_error', 'upstream_error_code' => $response->json('error.status')],
                    'temporary' => in_array($response->status(), [429, 500, 502, 503, 504], true)
                        && ($response->status() !== 429 || config('services.ai.fallback_on_rate_limit', true)),
                ];
            }

            $parts = collect($response->json('candidates.0.content.parts', []));
            $calls = $parts->pluck('functionCall')->filter()->values();

            if ($tools !== [] && $calls->isNotEmpty()) {
                $call = $calls->count() === 1 ? $calls->first() : null;
                $arguments = is_array($call) ? ($call['args'] ?? []) : null;

                if (! is_array($call) || ! in_array($call['name'] ?? null, array_column($tools, 'name'), true)
                    || ! is_array($arguments) || ($arguments !== [] && array_is_list($arguments))) {
                    return ['error' => __('AI service returned an invalid response.'), 'status' => 502, 'retryable_output' => true, 'diagnostics' => ['upstream_status' => $response->status(), 'failure_reason' => 'invalid_tool_call']];
                }

                return ['tool_call' => ['name' => $call['name'], 'arguments' => $arguments], 'diagnostics' => ['upstream_status' => $response->status()]];
            }

            $text = $parts->reject(static fn (mixed $part): bool => ! is_array($part) || ($part['thought'] ?? false) === true)
                ->pluck('text')->filter(static fn (mixed $text): bool => is_string($text))->implode('');

            if ($text === '') {
                return [
                    'error' => __('AI service returned no usable response.'),
                    'diagnostics' => ['upstream_status' => $response->status(), 'failure_reason' => 'empty_response'],
                    'status' => 502,
                    'retryable_output' => $tools !== [],
                ];
            }

            return ['text' => $text, 'diagnostics' => ['upstream_status' => $response->status()]];
        } catch (ConnectionException $e) {
            preg_match('/\bcURL error (\d{1,3})\b/', $e->getMessage(), $matches);
            $curlError = isset($matches[1]) ? (int) $matches[1] : null;
            Log::error('Gemini connection failed', [
                'error_type' => get_class($e),
            ]);

            return [
                'temporary' => in_array($curlError, [6, 7, 28, 52, 55, 56], true),
                'diagnostics' => ['upstream_status' => null, 'failure_reason' => $curlError === 28 ? 'connection_timeout' : 'connection_failure', 'curl_error' => $curlError],
                'error' => __('Unable to connect to the AI service.'),
                'status' => 502,
            ];
        }
    }
}
