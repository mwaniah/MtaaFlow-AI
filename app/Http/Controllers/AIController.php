<?php

namespace App\Http\Controllers;

use App\Currency;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class AIController extends Controller
{
    public function generateInvoice(Request $request): JsonResponse
    {
        $request->validate([
            'prompt' => ['required', 'string', 'max:5000'],
        ]);

        return $this->respond($request, $request->string('prompt')->toString(), true);
    }

    public function ask(Request $request): JsonResponse
    {
        $request->validate([
            'question' => ['required', 'string', 'max:5000'],
        ]);

        return $this->respond($request, $request->string('question')->toString(), false);
    }

    private function respond(Request $request, string $question, bool $invoiceOnly): JsonResponse
    {
        if (! config('services.gemini.key')) {
            return response()->json(['message' => 'AI integration is not configured yet.'], 503);
        }

        $schema = [
            'type' => 'OBJECT',
            'properties' => [
                'intent' => ['type' => 'STRING', 'enum' => ['invoice', 'chat']],
                'message' => ['type' => 'STRING'],
                'customer_name' => ['type' => 'STRING', 'nullable' => true],
                'currency' => ['type' => 'STRING', 'nullable' => true],
                'issue_date' => ['type' => 'STRING', 'nullable' => true],
                'due_date' => ['type' => 'STRING', 'nullable' => true],
                'items' => ['type' => 'ARRAY', 'items' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'description' => ['type' => 'STRING', 'nullable' => true],
                        'quantity' => ['type' => 'INTEGER', 'nullable' => true],
                        'unit_price' => ['type' => 'STRING', 'nullable' => true],
                    ],
                    'required' => ['description', 'quantity', 'unit_price'],
                ]],
            ],
            'required' => ['intent', 'message', 'customer_name', 'currency', 'issue_date', 'due_date', 'items'],
        ];

        try {
            $response = Http::connectTimeout(5)->timeout(45)
                ->withHeaders(['x-goog-api-key' => config('services.gemini.key')])
                ->post('https://generativelanguage.googleapis.com/v1beta/models/'.config('services.gemini.model').':generateContent', [
                    'systemInstruction' => ['parts' => [['text' => 'You are MtaaFlow AI, a business assistant. Return chat advice or extract an invoice for review only. Never claim to save, approve or pay anything. Never invent customers, currency, prices or dates. Missing fields must be null; missing items an empty array. Quantity may be 1 for a single described service. Prices must be plain decimal strings. Today is '.today()->toDateString().'. Resolve explicitly specified dates relative to today; missing issue date may be today; missing due date must be null. '.($invoiceOnly ? 'Always use invoice intent.' : ''),
                    ]]],
                    'contents' => [['role' => 'user', 'parts' => [['text' => $question]]]],
                    'generationConfig' => ['responseMimeType' => 'application/json', 'responseSchema' => $schema],
                ])->throw();
            $data = json_decode($response->json('candidates.0.content.parts.0.text', ''), true, 64, JSON_THROW_ON_ERROR);
        } catch (ConnectionException|RequestException|\JsonException|\TypeError $exception) {
            return response()->json(['message' => 'The AI could not produce a valid response. Please try again.'], 502);
        }

        $validator = Validator::make(is_array($data) ? $data : [], [
            'intent' => ['required', Rule::in(['invoice', 'chat'])],
            'message' => 'required|string|max:10000',
            'customer_name' => 'nullable|string|max:255',
            'currency' => ['nullable', Rule::in(array_keys(Currency::all()))],
            'issue_date' => 'nullable|date_format:Y-m-d',
            'due_date' => 'nullable|date_format:Y-m-d|after_or_equal:issue_date',
            'items' => 'present|array|max:100',
            'items.*.description' => 'nullable|string|max:255',
            'items.*.quantity' => 'nullable|integer|min:1|max:10000',
            'items.*.unit_price' => 'nullable|string|regex:/^\d+(?:\.\d{1,4})?$/|numeric|min:0|max:999999.99',
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'The AI response contains invalid invoice details. Please clarify your request.', 'errors' => $validator->errors()], 422);
        }
        $data = $validator->validated();
        foreach ($data['items'] as $item) {
            $fraction = explode('.', $item['unit_price'] ?? '', 2)[1] ?? '';
            if (strlen($fraction) > Currency::precision($data['currency'] ?? '')) {
                return response()->json(['message' => 'The AI price has too many decimal places for the invoice currency. Please clarify your request.'], 422);
            }
        }
        if ($data['intent'] === 'chat') {
            return response()->json(['message' => $data['message'], 'draft' => null]);
        }
        $name = trim($data['customer_name'] ?? '');
        $matches = $name === '' ? collect() : $request->user()->customers()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->get(['id', 'name', 'email']);
        $data['issue_date'] ??= today()->toDateString();
        $data['customer_id'] = $matches->count() === 1 ? $matches->first()->id : null;

        return response()->json([
            'message' => $data['message'],
            'draft' => $data,
            'customer_matches' => $matches,
            'customer_message' => $matches->isEmpty() ? 'Select an existing customer or create one with a name and email.' : ($matches->count() > 1 ? 'Several customers match. Select the correct customer.' : null),
        ]);
    }
}
