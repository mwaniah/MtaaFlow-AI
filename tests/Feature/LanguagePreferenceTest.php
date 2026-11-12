<?php

use App\Models\InvoiceShare;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;

test('guests receive the best supported browser language', function (string $languages, string $expected) {
    $this->withHeader('Accept-Language', $languages)->get(route('home'))
        ->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('locale', $expected));
})->with([
    ['en-US,en;q=0.9', 'en'], ['fr-CA,fr;q=0.9,en;q=0.8', 'fr'],
    ['de-DE,de;q=0.9', 'de'], ['es-MX,es;q=0.9', 'es'], ['ko-KR', 'en'],
]);

test('a guest selection overrides browser detection on public and authentication pages', function () {
    $this->withUnencryptedCookie('locale', 'es')->withHeader('Accept-Language', 'fr-FR');
    foreach (['home', 'login', 'register'] as $route) {
        $this->get(route($route))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('locale', 'es'));
    }
});

test('a saved account language takes priority on login and subsequent navigation', function () {
    $user = User::factory()->create(['locale' => 'de']);
    $this->withUnencryptedCookie('locale', 'fr');
    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect();
    foreach (['dashboard', 'customers.index', 'invoices.index', 'payments.index', 'inventory.index', 'reports.index', 'profile.edit'] as $route) {
        $this->get(route($route))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('locale', 'de'));
    }
    expect($user->fresh()->locale)->toBe('de');
});

test('an account without a saved language adopts the guest preference on login', function () {
    $user = User::factory()->create(['locale' => null]);
    $this->withUnencryptedCookie('locale', 'fr')->post(route('login.store'), [
        'email' => $user->email, 'password' => 'password',
    ])->assertRedirect();
    expect($user->fresh()->locale)->toBe('fr');
});

test('language changes persist only the authenticated user preference and preserve business records', function () {
    $user = User::factory()->create(['locale' => 'en', 'preferred_currency' => 'USD']);
    $other = User::factory()->create(['locale' => 'de']);
    $customer = $user->customers()->create(['name' => 'Élodie García', 'email' => 'client@example.test']);
    $invoice = $user->invoices()->create([
        'customer_id' => $customer->id, 'invoice_number' => 'LOCALE-USD', 'currency' => 'USD',
        'issue_date' => '2026-11-11', 'subtotal' => '100.50', 'total' => '100.50', 'status' => 'approved',
    ]);
    $payment = $invoice->payments()->create(['amount' => '25.25', 'currency' => 'USD', 'status' => 'completed']);
    $before = [$customer->refresh()->getAttributes(), $invoice->refresh()->getAttributes(), $payment->refresh()->getAttributes()];

    $this->actingAs($user)->patchJson(route('language.update'), ['locale' => 'fr', 'preferred_currency' => 'EUR', 'user_id' => $other->id])
        ->assertOk()->assertJsonPath('locale', 'fr');
    expect($user->fresh()->locale)->toBe('fr')
        ->and($user->fresh()->preferred_currency)->toBe('USD')
        ->and($other->fresh()->locale)->toBe('de')
        ->and([$customer->fresh()->getAttributes(), $invoice->fresh()->getAttributes(), $payment->fresh()->getAttributes()])->toBe($before);
    $this->withUnencryptedCookie('appearance', 'dark')->get(route('appearance.edit'))
        ->assertOk()->assertSee('class="dark"', false)
        ->assertInertia(fn (AssertableInertia $page) => $page->where('locale', 'fr'));
});

test('invalid account languages are rejected without overwriting the preference', function () {
    $user = User::factory()->create(['locale' => 'es']);
    $this->actingAs($user)->patchJson(route('language.update'), ['locale' => 'sw'])
        ->assertUnprocessable()->assertJsonValidationErrors('locale');
    expect($user->fresh()->locale)->toBe('es');
});

test('guests cannot update an account preference', function () {
    $this->patchJson(route('language.update'), ['locale' => 'fr'])->assertUnauthorized();
});

test('customer validation requires phone instead of email in the account language', function (string $locale, string $phoneError) {
    $this->actingAs(User::factory()->create(['locale' => $locale]))
        ->postJson(route('customers.store'), ['name' => 'Unchanged Name'])
        ->assertUnprocessable()->assertJsonPath('errors.phone.0', $phoneError)
        ->assertJsonMissingPath('errors.email');
})->with([
    ['en', 'The phone field is required.'],
    ['fr', 'Le champ téléphone est obligatoire.'],
    ['de', 'Das Feld Telefon ist erforderlich.'],
    ['es', 'El campo teléfono es obligatorio.'],
]);

test('authentication errors are localized for guests', function () {
    $this->withCredentials()->withUnencryptedCookie('locale', 'fr')->postJson(route('login.store'), [
        'email' => 'unknown@example.test', 'password' => 'incorrect-password',
    ])->assertUnprocessable()->assertJsonPath('errors.email.0', 'Ces identifiants ne correspondent pas à nos données.');
});

test('public invoice recipients use their own language without changing the owner or invoice', function () {
    Http::preventStrayRequests();
    config(['services.paypal.client_id' => null, 'services.paypal.client_secret' => null]);
    $token = str_repeat('l', 64);
    $share = InvoiceShare::factory()->create(['token_hash' => hash('sha256', $token)]);
    $owner = $share->invoice->user;
    $owner->update(['locale' => 'de']);
    $before = $share->invoice->getAttributes();
    $this->withCredentials()->withUnencryptedCookie('locale', 'fr')->getJson(route('invoice-shares.public', ['token' => $token]))
        ->assertOk()->assertJsonPath('checkout.reason', 'Le paiement PayPal est actuellement indisponible.')
        ->assertJsonPath('checkout.reason_code', 'configuration')
        ->assertJsonPath('invoice.currency', 'USD')->assertJsonPath('invoice.remaining_balance', '1.00');
    expect($share->invoice->fresh()->getAttributes())->toBe($before)
        ->and($owner->fresh()->locale)->toBe('de');
    Http::assertNothingSent();
});

test('verified assistant account results use the selected response language without translating business data', function (string $locale, string $heading) {
    config(['services.ai.primary_provider' => 'groq', 'services.ai.fallback_provider' => null, 'services.groq.key' => 'test-key']);
    Http::preventStrayRequests();
    Http::fake(['https://api.groq.com/openai/v1/chat/completions' => Http::response([
        'choices' => [['message' => ['content' => json_encode(['intent' => 'account_read', 'query' => 'customers', 'options' => ['mode' => 'list']])]]],
    ])]);
    $user = User::factory()->create(['locale' => 'en']);
    $user->customers()->create(['name' => 'Élodie García', 'email' => 'private@example.test']);
    $this->actingAs($user)->postJson(route('ai.ask'), ['question' => 'List my customers', 'locale' => $locale])
        ->assertOk()->assertJsonPath('data.count', 1)
        ->assertJsonPath('data.customers.0.name', 'Élodie García')
        ->assertJsonPath('answer', fn (string $answer): bool => str_contains($answer, $heading) && str_contains($answer, 'Élodie García'));
    expect($user->fresh()->locale)->toBe('en');
    Http::assertSentCount(1);
})->with([['fr', 'Clients : 1'], ['de', 'Kunden: 1'], ['es', 'Clientes: 1']]);
