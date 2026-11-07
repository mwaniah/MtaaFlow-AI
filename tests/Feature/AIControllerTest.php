<?php

use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['services.gemini.key' => null]);
    Http::preventStrayRequests();
});

test('invoice generation returns 503 while AI is not configured', function () {
    $response = $this->actingAs(User::factory()->create())
        ->postJson(route('ai.invoice.generate'), ['prompt' => 'Draft an invoice for design work.']);

    $response->assertServiceUnavailable()
        ->assertJson(['message' => 'AI integration is not configured yet.']);
});

test('questions return 503 while AI is not configured', function () {
    $response = $this->actingAs(User::factory()->create())
        ->postJson(route('ai.ask'), ['question' => 'How should I follow up?']);

    $response->assertServiceUnavailable()
        ->assertJson(['message' => 'AI integration is not configured yet.']);
});

test('invoice generation returns 422 when a prompt is missing', function () {
    $response = $this->actingAs(User::factory()->create())
        ->postJson(route('ai.invoice.generate'), []);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors('prompt');
});

test('questions return 422 when a question is missing', function () {
    $response = $this->actingAs(User::factory()->create())
        ->postJson(route('ai.ask'), []);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors('question');
});

test('AI routes return 401 when a freelancer is not authenticated', function (string $route, array $data) {
    $response = $this->postJson(route($route), $data);

    $response->assertUnauthorized();
})->with([
    'invoice generation' => ['ai.invoice.generate', ['prompt' => 'Create an invoice.']],
    'questions' => ['ai.ask', ['question' => 'What is an invoice?']],
]);
