<?php

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

beforeEach(function (): void {
    config([
        'services.paypal.client_id' => 'public-sandbox-client',
        'services.paypal.client_secret' => 'public-sandbox-secret',
        'services.paypal.base_url' => 'https://api-m.sandbox.paypal.com',
        'services.paypal.mode' => 'sandbox',
        'inertia.ssr.enabled' => false,
    ]);
});

function publicCheckoutInvoice(User $owner, string $status = 'approved', string $currency = 'USD'): Invoice
{
    $customer = $owner->customers()->create([
        'name' => 'Shared Customer',
        'email' => 'private-customer@example.test',
        'phone' => '+254700000000',
        'address' => 'Private customer address',
    ]);
    $invoice = $owner->invoices()->create([
        'customer_id' => $customer->id,
        'invoice_number' => 'SHARED-'.Str::upper(Str::random(12)),
        'currency' => $currency,
        'subtotal' => '100',
        'total' => '100',
        'status' => $status,
        'issue_date' => '2026-11-10',
    ]);
    $invoice->items()->create([
        'description' => 'Manual design work',
        'quantity' => 2,
        'unit_price' => '50',
        'amount' => '100',
    ]);

    return $invoice;
}

/**
 * @param  array<string, mixed>  $attributes
 */
function publicCheckoutShare(Invoice $invoice, array $attributes = [], string $character = 's'): string
{
    $token = str_repeat($character, 64);
    $invoice->shares()->create([
        'token_hash' => hash('sha256', $token),
        'expires_at' => now()->addDay(),
        ...$attributes,
    ]);

    return $token;
}

/**
 * @return array<string, mixed>
 */
function publicCheckoutCaptureBody(Payment $payment, string $amount = '100.00', string $currency = 'USD'): array
{
    return [
        'id' => $payment->paypal_order_id,
        'status' => 'COMPLETED',
        'purchase_units' => [[
            'reference_id' => 'invoice-'.$payment->invoice_id,
            'custom_id' => 'payment-'.$payment->id,
            'amount' => ['currency_code' => $currency, 'value' => $amount],
            'payments' => ['captures' => [[
                'id' => 'SHARED-CAPTURE-1',
                'status' => 'COMPLETED',
                'amount' => ['currency_code' => $currency, 'value' => $amount],
            ]]],
        ]],
    ];
}

/**
 * @return array<string, mixed>
 */
function publicCheckoutOrderBody(Payment $payment, string $approvalUrl = 'https://www.sandbox.paypal.com/checkoutnow?token=SHARED-ORDER-1'): array
{
    return [
        'id' => $payment->paypal_order_id,
        'status' => 'CREATED',
        'intent' => 'CAPTURE',
        'purchase_units' => [[
            'reference_id' => 'invoice-'.$payment->invoice_id,
            'custom_id' => 'payment-'.$payment->id,
            'amount' => ['currency_code' => $payment->currency, 'value' => number_format((float) $payment->amount, 2, '.', '')],
        ]],
        'links' => [['rel' => 'approve', 'href' => $approvalUrl]],
    ];
}

function fakePublicCheckoutPayPal(string $amount = '100.00', string $currency = 'USD'): void
{
    Http::preventStrayRequests();
    Http::fake([
        'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response([
            'access_token' => 'public-sandbox-token',
            'expires_in' => 3600,
        ]),
        'api-m.sandbox.paypal.com/v2/checkout/orders' => Http::response([
            'id' => 'SHARED-ORDER-1',
            'status' => 'CREATED',
            'links' => [[
                'rel' => 'approve',
                'href' => 'https://www.sandbox.paypal.com/checkoutnow?token=SHARED-ORDER-1',
            ]],
        ], 201),
        'api-m.sandbox.paypal.com/v2/checkout/orders/SHARED-ORDER-1/capture' => static function () use ($amount, $currency): PromiseInterface {
            $payment = Payment::query()->where('paypal_order_id', 'SHARED-ORDER-1')->firstOrFail();

            return Http::response(publicCheckoutCaptureBody($payment, $amount, $currency), 201);
        },
        'api-m.sandbox.paypal.com/v2/checkout/orders/SHARED-ORDER-1' => static function (): PromiseInterface {
            $payment = Payment::query()->where('paypal_order_id', 'SHARED-ORDER-1')->firstOrFail();

            return Http::response(publicCheckoutOrderBody($payment));
        },
    ]);
}

test('a manually created and approved invoice can be shared and paid by a guest exactly once', function (): void {
    fakePublicCheckoutPayPal();
    $owner = User::factory()->unverified()->create();
    $customer = $owner->customers()->create([
        'name' => 'Manual Invoice Customer',
        'email' => 'private-manual@example.test',
        'phone' => '+254700000000',
        'address' => 'Private address',
    ]);

    $this->actingAs($owner)->post(route('invoices.store'), [
        'customer_id' => $customer->id,
        'currency' => 'USD',
        'issue_date' => '2026-11-10',
        'due_date' => '2026-11-30',
        'items' => [['description' => 'Manual design work', 'quantity' => 2, 'unit_price' => '50.00']],
    ])->assertRedirect(route('invoices.index'));
    $invoice = $owner->invoices()->sole();
    expect($invoice->status)->toBe('draft');
    $this->post(route('invoices.approve', $invoice))->assertRedirect(route('invoices.show', $invoice));
    $share = $this->postJson(route('invoices.shares.store', $invoice))->assertCreated();
    $token = $share->json('share_token');
    Auth::logout();
    $this->flushSession();
    $this->assertGuest();

    $this->get($share->json('share_url'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Invoices/Public')
            ->where('auth.user', null)
            ->where('invoice.invoice_number', $invoice->invoice_number)
            ->where('invoice.items.0.description', 'Manual design work')
            ->where('invoice.total', '100.00')
            ->where('checkout.available', true));
    $this->getJson(route('invoice-shares.public', ['token' => $token]))->assertOk()
        ->assertJsonPath('invoice.customer_name', 'Manual Invoice Customer')
        ->assertJsonPath('invoice.currency', 'USD')
        ->assertJsonPath('invoice.remaining_balance', '100.00')
        ->assertJsonMissingPath('invoice.user_id')
        ->assertJsonMissingPath('invoice.customer_id')
        ->assertJsonMissingPath('invoice.customer.email')
        ->assertJsonMissing(['email' => 'private-manual@example.test', 'address' => 'Private address']);

    $orderRoute = route('invoice-shares.paypal.orders', ['token' => $token]);
    $idempotencyKey = (string) Str::uuid();
    $this->postJson($orderRoute, ['idempotency_key' => $idempotencyKey])->assertOk()
        ->assertJsonPath('order_id', 'SHARED-ORDER-1')
        ->assertJsonPath('amount', '100.00')
        ->assertJsonPath('currency', 'USD')
        ->assertJsonPath('status', 'pending');
    $this->postJson($orderRoute, ['idempotency_key' => $idempotencyKey])->assertOk()
        ->assertJsonPath('order_id', 'SHARED-ORDER-1');
    $this->assertDatabaseCount('payments', 1);
    $this->assertDatabaseHas('payments', ['invoice_id' => $invoice->id, 'status' => 'pending', 'paid_at' => null]);
    Http::assertSentCount(3);
    Http::assertSent(fn (ClientRequest $request): bool => str_ends_with($request->url(), '/v2/checkout/orders')
        && $request->hasHeader('Prefer', 'return=representation')
        && $request['purchase_units'][0]['amount'] === ['currency_code' => 'USD', 'value' => '100.00']
        && $request['payment_source']['paypal']['experience_context']['return_url'] === route('invoice-shares.public', ['token' => $token, 'checkout' => 'approved'])
        && $request['payment_source']['paypal']['experience_context']['cancel_url'] === route('invoice-shares.public', ['token' => $token, 'checkout' => 'cancelled']));

    $captureRoute = route('invoice-shares.paypal.capture', ['token' => $token, 'orderId' => 'SHARED-ORDER-1']);
    $this->postJson($captureRoute)->assertOk()
        ->assertJsonPath('message', 'Payment captured and verified.')
        ->assertJsonPath('payment.status', 'completed')
        ->assertJsonPath('payment.amount', '100.00')
        ->assertJsonPath('invoice.status', 'paid')
        ->assertJsonPath('invoice.remaining_balance', '0.00');
    $this->postJson($captureRoute)->assertOk()->assertJsonPath('payment.status', 'completed');
    $this->postJson($orderRoute, ['idempotency_key' => (string) Str::uuid()])->assertUnprocessable();
    $this->getJson(route('invoice-shares.public', ['token' => $token]))->assertOk()
        ->assertJsonPath('invoice.status', 'paid')
        ->assertJsonPath('invoice.payments_received', '100.00')
        ->assertJsonPath('invoice.remaining_balance', '0.00')
        ->assertJsonPath('checkout.available', false);
    $this->assertDatabaseCount('payments', 1);
    $this->assertDatabaseHas('payments', ['invoice_id' => $invoice->id, 'status' => 'completed', 'paypal_capture_id' => 'SHARED-CAPTURE-1']);
    $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'status' => 'paid']);
    Http::assertSentCount(4);
    Http::assertSent(fn (ClientRequest $request): bool => str_ends_with($request->url(), '/SHARED-ORDER-1/capture')
        && $request->hasHeader('Prefer', 'return=representation'));

    $this->actingAs($owner)->getJson(route('payments.index'))->assertOk()
        ->assertJsonCount(1, 'payments')->assertJsonPath('payments.0.status', 'completed');
    $this->get(route('dashboard'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('paidInvoiceCount', 1)
            ->where('outstandingInvoiceCount', 0)
            ->where('recentPayments.0.paypal_capture_id', 'SHARED-CAPTURE-1')
            ->where('verifiedEarningsByCurrency.0.currency', 'USD')
            ->where('verifiedEarningsByCurrency.0.total', fn (mixed $total): bool => (float) $total === 100.0));
    $this->get(route('reports.index'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('paidInvoiceCount', 1)
            ->where('unpaidInvoiceCount', 0)
            ->where('verifiedEarningsByCurrency.0.currency', 'USD')
            ->where('verifiedEarningsByCurrency.0.total', '100.00')
            ->where('customerPaymentSummaries.0.customerName', 'Manual Invoice Customer')
            ->where('customerPaymentSummaries.0.total', '100.00'));
});

test('guest checkout pays the actual remaining balance and preserves existing partial payments', function (): void {
    fakePublicCheckoutPayPal('75.00', 'EUR');
    $invoice = publicCheckoutInvoice(User::factory()->create(), currency: 'EUR');
    $invoice->payments()->create(['amount' => '25', 'currency' => 'EUR', 'status' => 'completed']);
    $token = publicCheckoutShare($invoice);

    $this->postJson(route('invoice-shares.paypal.orders', ['token' => $token]), [
        'idempotency_key' => (string) Str::uuid(),
    ])->assertOk()->assertJsonPath('amount', '75.00')->assertJsonPath('currency', 'EUR');
    $this->postJson(route('invoice-shares.paypal.capture', ['token' => $token, 'orderId' => 'SHARED-ORDER-1']))
        ->assertOk()->assertJsonPath('invoice.remaining_balance', '0.00')->assertJsonPath('invoice.status', 'paid');

    $this->assertDatabaseCount('payments', 2);
    $this->assertDatabaseHas('payments', ['invoice_id' => $invoice->id, 'amount' => '75.0000', 'currency' => 'EUR', 'status' => 'completed']);
    Http::assertSent(fn (ClientRequest $request): bool => str_ends_with($request->url(), '/v2/checkout/orders')
        && $request['purchase_units'][0]['amount'] === ['currency_code' => 'EUR', 'value' => '75.00']);
    Http::assertSentCount(3);
});

test('guest checkout retains the existing validated partial payment option', function (): void {
    fakePublicCheckoutPayPal('25.00');
    $invoice = publicCheckoutInvoice(User::factory()->create());
    $token = publicCheckoutShare($invoice);

    $this->postJson(route('invoice-shares.paypal.orders', ['token' => $token]), [
        'idempotency_key' => (string) Str::uuid(), 'amount' => '25.00',
    ])->assertOk()->assertJsonPath('amount', '25.00');
    $this->postJson(route('invoice-shares.paypal.capture', ['token' => $token, 'orderId' => 'SHARED-ORDER-1']))
        ->assertOk()->assertJsonPath('invoice.remaining_balance', '75.00')->assertJsonPath('invoice.status', 'approved');

    $this->assertDatabaseHas('payments', ['invoice_id' => $invoice->id, 'amount' => '25.0000', 'status' => 'completed']);
    $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'status' => 'approved']);
    Http::assertSentCount(3);
});

test('invalid expired and revoked shares return 404 for viewing ordering and capture', function (array $attributes, string $requestedCharacter): void {
    $this->travelTo('2026-11-10 12:00:00');
    Http::preventStrayRequests();
    $invoice = publicCheckoutInvoice(User::factory()->create());
    publicCheckoutShare($invoice, $attributes);
    $token = str_repeat($requestedCharacter, 64);
    $invoice->payments()->create([
        'paypal_order_id' => 'SHARED-ORDER-1', 'amount' => '100', 'currency' => 'USD', 'status' => 'pending',
        'idempotency_key' => (string) Str::uuid(), 'paypal_capture_request_id' => (string) Str::uuid(),
    ]);

    $this->getJson(route('invoice-shares.public', ['token' => $token]))->assertNotFound();
    $this->postJson(route('invoice-shares.paypal.orders', ['token' => $token]), [
        'idempotency_key' => (string) Str::uuid(),
    ])->assertNotFound();
    $this->postJson(route('invoice-shares.paypal.capture', ['token' => $token, 'orderId' => 'SHARED-ORDER-1']))->assertNotFound();

    $this->assertDatabaseHas('payments', ['invoice_id' => $invoice->id, 'status' => 'pending', 'paid_at' => null]);
    Http::assertNothingSent();
})->with([
    'unknown token' => [[], 'x'],
    'expired token' => [['expires_at' => '2026-11-10 11:59:00'], 's'],
    'revoked token' => [['revoked_at' => '2026-11-10 11:59:00'], 's'],
]);

test('a valid share cannot capture an order for a different invoice', function (): void {
    Http::preventStrayRequests();
    $invoice = publicCheckoutInvoice(User::factory()->create());
    $token = publicCheckoutShare($invoice);
    $otherInvoice = publicCheckoutInvoice(User::factory()->create());
    $otherInvoice->payments()->create([
        'paypal_order_id' => 'PRIVATE-ORDER-1', 'amount' => '100', 'currency' => 'USD', 'status' => 'pending',
        'idempotency_key' => (string) Str::uuid(), 'paypal_capture_request_id' => (string) Str::uuid(),
    ]);

    $this->postJson(route('invoice-shares.paypal.capture', ['token' => $token, 'orderId' => 'PRIVATE-ORDER-1']))->assertNotFound();

    $this->assertDatabaseCount('payments', 1);
    $this->assertDatabaseHas('payments', ['invoice_id' => $otherInvoice->id, 'status' => 'pending', 'paid_at' => null]);
    Http::assertNothingSent();
});

test('public checkout rejects manipulated invoice currency and invalid payment amounts', function (array $attributes, string $field): void {
    Http::preventStrayRequests();
    $invoice = publicCheckoutInvoice(User::factory()->create());
    $token = publicCheckoutShare($invoice);

    $this->postJson(route('invoice-shares.paypal.orders', ['token' => $token]), [
        'idempotency_key' => (string) Str::uuid(), ...$attributes,
    ])->assertUnprocessable()->assertJsonValidationErrors($field);

    $this->assertDatabaseCount('payments', 0);
    Http::assertNothingSent();
})->with([
    'currency override' => [['currency' => 'EUR'], 'currency'],
    'invoice override' => [['invoice_id' => 99999], 'invoice_id'],
    'owner override' => [['user_id' => 99999], 'user_id'],
    'above balance' => [['amount' => '100.01'], 'amount'],
    'zero amount' => [['amount' => '0.00'], 'amount'],
    'negative amount' => [['amount' => '-1.00'], 'amount'],
    'excess currency precision' => [['amount' => '1.001'], 'amount'],
    'invalid amount' => [['amount' => 'not-an-amount'], 'amount'],
]);

test('shared draft cancelled and paid invoices never permit a new checkout', function (string $status): void {
    Http::preventStrayRequests();
    $invoice = publicCheckoutInvoice(User::factory()->create(), $status);
    $token = publicCheckoutShare($invoice);

    $this->getJson(route('invoice-shares.public', ['token' => $token]))->assertOk()
        ->assertJsonPath('invoice.status', $status)->assertJsonPath('checkout.available', false);
    $this->postJson(route('invoice-shares.paypal.orders', ['token' => $token]), [
        'idempotency_key' => (string) Str::uuid(),
    ])->assertUnprocessable();

    $this->assertDatabaseCount('payments', 0);
    Http::assertNothingSent();
})->with(['draft', 'cancelled', 'paid']);

test('PayPal cancellation never captures a payment and the same pending checkout can be resumed', function (): void {
    fakePublicCheckoutPayPal();
    $invoice = publicCheckoutInvoice(User::factory()->create());
    $token = publicCheckoutShare($invoice);
    $orderRoute = route('invoice-shares.paypal.orders', ['token' => $token]);
    $this->postJson($orderRoute, ['idempotency_key' => (string) Str::uuid()])->assertOk();

    $this->get(route('invoice-shares.public', ['token' => $token, 'checkout' => 'cancelled', 'token_from_paypal' => 'SHARED-ORDER-1']))
        ->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('Invoices/Public'));
    $this->postJson($orderRoute, ['idempotency_key' => (string) Str::uuid()])->assertOk()
        ->assertJsonPath('order_id', 'SHARED-ORDER-1')->assertJsonPath('status', 'pending');

    $this->assertDatabaseCount('payments', 1);
    $this->assertDatabaseHas('payments', ['invoice_id' => $invoice->id, 'status' => 'pending', 'paid_at' => null, 'paypal_capture_id' => null]);
    $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'status' => 'approved']);
    Http::assertSentCount(3);
});

test('guest capture only records completed PayPal responses matching the intended payment', function (string $path, mixed $value): void {
    Http::preventStrayRequests();
    $invoice = publicCheckoutInvoice(User::factory()->create());
    $token = publicCheckoutShare($invoice);
    $payment = $invoice->payments()->create([
        'paypal_order_id' => 'SHARED-ORDER-1', 'amount' => '100', 'currency' => 'USD', 'status' => 'pending',
        'idempotency_key' => (string) Str::uuid(), 'paypal_capture_request_id' => (string) Str::uuid(),
    ]);
    $captureBody = publicCheckoutCaptureBody($payment);
    data_set($captureBody, $path, $value);
    Http::fake([
        'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'public-sandbox-token', 'expires_in' => 3600]),
        'api-m.sandbox.paypal.com/v2/checkout/orders/SHARED-ORDER-1/capture' => Http::response($captureBody, 201),
    ]);

    $this->postJson(route('invoice-shares.paypal.capture', ['token' => $token, 'orderId' => 'SHARED-ORDER-1']), [
        'status' => 'completed', 'amount' => '100.00',
    ])->assertStatus(502)->assertJsonPath('message', 'PayPal could not verify the captured payment.');

    $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'pending', 'paid_at' => null, 'paypal_capture_id' => null]);
    $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'status' => 'approved']);
    Http::assertSentCount(2);
})->with([
    'uncompleted order' => ['status', 'APPROVED'],
    'wrong invoice' => ['purchase_units.0.reference_id', 'invoice-99999'],
    'wrong payment' => ['purchase_units.0.custom_id', 'payment-99999'],
    'wrong purchase amount' => ['purchase_units.0.amount.value', '99.00'],
    'wrong capture amount' => ['purchase_units.0.payments.captures.0.amount.value', '99.00'],
    'wrong currency' => ['purchase_units.0.payments.captures.0.amount.currency_code', 'EUR'],
    'pending capture' => ['purchase_units.0.payments.captures.0.status', 'PENDING'],
    'missing capture id' => ['purchase_units.0.payments.captures.0.id', ''],
]);

test('PayPal capture failure leaves the guest payment pending and the invoice unpaid', function (): void {
    Http::preventStrayRequests();
    $invoice = publicCheckoutInvoice(User::factory()->create());
    $token = publicCheckoutShare($invoice);
    $payment = $invoice->payments()->create([
        'paypal_order_id' => 'SHARED-ORDER-1', 'amount' => '100', 'currency' => 'USD', 'status' => 'pending',
        'idempotency_key' => (string) Str::uuid(), 'paypal_capture_request_id' => (string) Str::uuid(),
    ]);
    Http::fake([
        'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'public-sandbox-token', 'expires_in' => 3600]),
        'api-m.sandbox.paypal.com/v2/checkout/orders/SHARED-ORDER-1/capture' => Http::response(['name' => 'SERVICE_UNAVAILABLE'], 503),
    ]);

    $this->postJson(route('invoice-shares.paypal.capture', ['token' => $token, 'orderId' => 'SHARED-ORDER-1']))
        ->assertStatus(502)->assertJsonPath('message', 'PayPal is temporarily unavailable.');

    $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'pending', 'paid_at' => null, 'paypal_capture_id' => null]);
    $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'status' => 'approved']);
    Http::assertSentCount(2);
});

test('public shares do not grant access to private freelancer invoice or payment endpoints', function (): void {
    Http::preventStrayRequests();
    $invoice = publicCheckoutInvoice(User::factory()->create());
    publicCheckoutShare($invoice);
    $payment = $invoice->payments()->create(['amount' => '100', 'currency' => 'USD', 'status' => 'pending']);

    $this->getJson(route('invoices.show', $invoice))->assertUnauthorized();
    $this->getJson(route('payments.index'))->assertUnauthorized();
    $this->getJson(route('payments.show', $payment))->assertUnauthorized();
    $this->postJson(route('payments.paypal.orders', $invoice), ['idempotency_key' => (string) Str::uuid()])->assertUnauthorized();
    $this->postJson(route('payments.paypal.capture', ['orderId' => 'PRIVATE-ORDER-1']))->assertUnauthorized();
    $this->postJson(route('invoices.shares.store', $invoice))->assertUnauthorized();

    Http::assertNothingSent();
});

test('resuming a public checkout cannot silently change the pending payment amount', function (): void {
    fakePublicCheckoutPayPal('25.00');
    $invoice = publicCheckoutInvoice(User::factory()->create());
    $token = publicCheckoutShare($invoice);
    $orderRoute = route('invoice-shares.paypal.orders', ['token' => $token]);
    $this->postJson($orderRoute, ['idempotency_key' => (string) Str::uuid(), 'amount' => '25.00'])->assertOk();

    $this->postJson($orderRoute, ['idempotency_key' => (string) Str::uuid(), 'amount' => '50.00'])->assertConflict();

    $this->assertDatabaseCount('payments', 1);
    $this->assertDatabaseHas('payments', ['invoice_id' => $invoice->id, 'amount' => '25.0000', 'status' => 'pending']);
    Http::assertSentCount(2);
});

test('resuming a valid guest order returns its unchanged approval URL without creating another payment', function (): void {
    Http::preventStrayRequests();
    $invoice = publicCheckoutInvoice(User::factory()->create());
    $token = publicCheckoutShare($invoice);
    $approvalUrl = 'https://www.sandbox.paypal.com/checkoutnow?token=SHARED-ORDER-1&useraction=commit&locale.x=fr_FR';
    $payment = $invoice->payments()->create([
        'paypal_order_id' => 'SHARED-ORDER-1', 'paypal_approval_url' => $approvalUrl,
        'amount' => '25', 'currency' => 'USD', 'status' => 'pending',
        'idempotency_key' => (string) Str::uuid(), 'paypal_capture_request_id' => (string) Str::uuid(),
    ]);
    Http::fake([
        'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'public-sandbox-token', 'expires_in' => 3600]),
        'api-m.sandbox.paypal.com/v2/checkout/orders/SHARED-ORDER-1' => Http::response(publicCheckoutOrderBody($payment, $approvalUrl)),
    ]);

    $this->postJson(route('invoice-shares.paypal.orders', ['token' => $token]), [
        'idempotency_key' => (string) Str::uuid(),
    ])->assertOk()->assertHeader('Content-Type', 'application/json')
        ->assertJsonPath('order_id', 'SHARED-ORDER-1')
        ->assertJsonPath('approval_url', $approvalUrl)
        ->assertJsonPath('amount', '25.00')
        ->assertJsonPath('status', 'pending');

    $this->assertDatabaseCount('payments', 1);
    $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'pending', 'amount' => '25.0000']);
    Http::assertSent(fn (ClientRequest $request): bool => $request->method() === 'GET'
        && str_ends_with($request->url(), '/v2/checkout/orders/SHARED-ORDER-1'));
    Http::assertNotSent(fn (ClientRequest $request): bool => $request->method() === 'POST'
        && str_ends_with($request->url(), '/v2/checkout/orders'));
});

test('an unavailable PayPal order is replaced once without changing the original guest payment amount', function (bool $reuseOriginalKey, array $providerResponse, int $providerStatus, string $oldStatus): void {
    Http::preventStrayRequests();
    $invoice = publicCheckoutInvoice(User::factory()->create());
    $token = publicCheckoutShare($invoice);
    $originalKey = (string) Str::uuid();
    $oldPayment = $invoice->payments()->create([
        'paypal_order_id' => 'EXPIRED-ORDER-1',
        'paypal_approval_url' => 'https://www.sandbox.paypal.com/checkoutnow?token=EXPIRED-ORDER-1',
        'amount' => '25', 'currency' => 'USD', 'status' => 'pending',
        'idempotency_key' => $originalKey, 'paypal_capture_request_id' => (string) Str::uuid(),
    ]);
    $approvalUrl = 'https://www.sandbox.paypal.com/checkoutnow?token=SHARED-ORDER-1&useraction=commit';
    Http::fake([
        'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'public-sandbox-token', 'expires_in' => 3600]),
        'api-m.sandbox.paypal.com/v2/checkout/orders/EXPIRED-ORDER-1' => Http::response($providerResponse, $providerStatus),
        'api-m.sandbox.paypal.com/v2/checkout/orders' => Http::response([
            'id' => 'SHARED-ORDER-1', 'status' => 'CREATED',
            'links' => [['rel' => 'approve', 'href' => $approvalUrl]],
        ], 201),
        'api-m.sandbox.paypal.com/v2/checkout/orders/SHARED-ORDER-1' => static function () use ($approvalUrl): PromiseInterface {
            $payment = Payment::query()->where('paypal_order_id', 'SHARED-ORDER-1')->firstOrFail();

            return Http::response(publicCheckoutOrderBody($payment, $approvalUrl));
        },
    ]);
    $route = route('invoice-shares.paypal.orders', ['token' => $token]);
    $payload = ['idempotency_key' => $reuseOriginalKey ? $originalKey : (string) Str::uuid()];

    $this->postJson($route, $payload)->assertOk()
        ->assertJsonPath('order_id', 'SHARED-ORDER-1')->assertJsonPath('approval_url', $approvalUrl)
        ->assertJsonPath('amount', '25.00')->assertJsonPath('currency', 'USD')->assertJsonPath('status', 'pending');
    $this->postJson($route, $payload)->assertOk()->assertJsonPath('order_id', 'SHARED-ORDER-1');

    $this->assertDatabaseCount('payments', 2);
    $this->assertDatabaseHas('payments', ['id' => $oldPayment->id, 'status' => $oldStatus, 'paid_at' => null]);
    $this->assertDatabaseHas('payments', ['invoice_id' => $invoice->id, 'paypal_order_id' => 'SHARED-ORDER-1', 'status' => 'pending', 'amount' => '25.0000', 'paid_at' => null]);
    $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'status' => 'approved']);
    Http::assertSent(fn (ClientRequest $request): bool => $request->method() === 'POST'
        && str_ends_with($request->url(), '/v2/checkout/orders')
        && $request->header('PayPal-Request-Id')[0] !== $originalKey
        && $request['purchase_units'][0]['amount'] === ['currency_code' => 'USD', 'value' => '25.00']);
    expect(Http::recorded(fn (ClientRequest $request): bool => $request->method() === 'POST'
        && str_ends_with($request->url(), '/v2/checkout/orders')))->toHaveCount(1);
})->with([
    'missing order with a new client request key' => [false, ['name' => 'RESOURCE_NOT_FOUND', 'details' => [['issue' => 'INVALID_RESOURCE_ID']]], 404, 'failed'],
    'missing order with the original client request key' => [true, ['name' => 'RESOURCE_NOT_FOUND', 'details' => [['issue' => 'INVALID_RESOURCE_ID']]], 404, 'failed'],
    'voided order' => [false, ['id' => 'EXPIRED-ORDER-1', 'status' => 'VOIDED'], 200, 'cancelled'],
]);

test('an uncertain PayPal order lookup never replaces or completes the pending guest payment', function (int $providerStatus, array $body, int $expectedStatus): void {
    Http::preventStrayRequests();
    $invoice = publicCheckoutInvoice(User::factory()->create());
    $token = publicCheckoutShare($invoice);
    $payment = $invoice->payments()->create([
        'paypal_order_id' => 'SHARED-ORDER-1',
        'paypal_approval_url' => 'https://www.sandbox.paypal.com/checkoutnow?token=SHARED-ORDER-1',
        'amount' => '100', 'currency' => 'USD', 'status' => 'pending',
        'idempotency_key' => (string) Str::uuid(), 'paypal_capture_request_id' => (string) Str::uuid(),
    ]);
    Http::fake([
        'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'public-sandbox-token', 'expires_in' => 3600]),
        'api-m.sandbox.paypal.com/v2/checkout/orders/SHARED-ORDER-1' => Http::response($body, $providerStatus),
    ]);

    $this->postJson(route('invoice-shares.paypal.orders', ['token' => $token]), [
        'idempotency_key' => (string) Str::uuid(),
    ])->assertStatus($expectedStatus);

    $this->assertDatabaseCount('payments', 1);
    $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'pending', 'paid_at' => null, 'paypal_capture_id' => null]);
    $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'status' => 'approved']);
    Http::assertNotSent(fn (ClientRequest $request): bool => $request->method() === 'POST'
        && str_ends_with($request->url(), '/v2/checkout/orders'));
})->with([
    'temporary provider failure' => [503, ['name' => 'SERVICE_UNAVAILABLE'], 502],
    'merchant cannot access the order' => [403, ['name' => 'NOT_AUTHORIZED'], 422],
    'unrecognized not found response' => [404, ['name' => 'RESOURCE_NOT_FOUND', 'details' => [['issue' => 'UNKNOWN_RESOURCE']]], 422],
]);

test('resuming a captured guest order verifies and synchronizes payment instead of starting checkout again', function (): void {
    Http::preventStrayRequests();
    $invoice = publicCheckoutInvoice(User::factory()->create());
    $token = publicCheckoutShare($invoice);
    $payment = $invoice->payments()->create([
        'paypal_order_id' => 'SHARED-ORDER-1',
        'paypal_approval_url' => 'https://www.sandbox.paypal.com/checkoutnow?token=SHARED-ORDER-1',
        'amount' => '100', 'currency' => 'USD', 'status' => 'pending',
        'idempotency_key' => (string) Str::uuid(), 'paypal_capture_request_id' => (string) Str::uuid(),
    ]);
    Http::fake([
        'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'public-sandbox-token', 'expires_in' => 3600]),
        'api-m.sandbox.paypal.com/v2/checkout/orders/SHARED-ORDER-1' => Http::response(publicCheckoutCaptureBody($payment)),
    ]);

    $this->postJson(route('invoice-shares.paypal.orders', ['token' => $token]), [
        'idempotency_key' => (string) Str::uuid(),
    ])->assertOk()->assertJsonPath('status', 'completed')->assertJsonPath('approval_url', null);

    $this->assertDatabaseCount('payments', 1);
    $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'completed', 'paypal_capture_id' => 'SHARED-CAPTURE-1']);
    $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'status' => 'paid']);
    Http::assertNotSent(fn (ClientRequest $request): bool => $request->method() === 'POST'
        && str_contains($request->url(), '/v2/checkout/orders'));
});

test('a completed PayPal order with an unverified amount never completes a guest payment', function (): void {
    Http::preventStrayRequests();
    $invoice = publicCheckoutInvoice(User::factory()->create());
    $token = publicCheckoutShare($invoice);
    $payment = $invoice->payments()->create([
        'paypal_order_id' => 'SHARED-ORDER-1', 'amount' => '100', 'currency' => 'USD', 'status' => 'pending',
        'idempotency_key' => (string) Str::uuid(), 'paypal_capture_request_id' => (string) Str::uuid(),
    ]);
    Http::fake([
        'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'public-sandbox-token', 'expires_in' => 3600]),
        'api-m.sandbox.paypal.com/v2/checkout/orders/SHARED-ORDER-1' => Http::response(publicCheckoutCaptureBody($payment, '99.00')),
    ]);

    $this->postJson(route('invoice-shares.paypal.orders', ['token' => $token]), [
        'idempotency_key' => (string) Str::uuid(),
    ])->assertStatus(502)->assertJsonPath('message', 'PayPal could not verify the captured payment.');

    $this->assertDatabaseCount('payments', 1);
    $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'pending', 'paid_at' => null, 'paypal_capture_id' => null]);
    $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'status' => 'approved']);
    Http::assertNotSent(fn (ClientRequest $request): bool => $request->method() === 'POST'
        && str_contains($request->url(), '/v2/checkout/orders'));
});

test('a previously approved guest order is captured without redirecting the buyer through approval again', function (): void {
    Http::preventStrayRequests();
    $invoice = publicCheckoutInvoice(User::factory()->create());
    $token = publicCheckoutShare($invoice);
    $payment = $invoice->payments()->create([
        'paypal_order_id' => 'SHARED-ORDER-1', 'amount' => '100', 'currency' => 'USD', 'status' => 'pending',
        'idempotency_key' => (string) Str::uuid(), 'paypal_capture_request_id' => (string) Str::uuid(),
    ]);
    $body = publicCheckoutOrderBody($payment);
    $body['status'] = 'APPROVED';
    $body['links'] = [];
    Http::fake([
        'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'public-sandbox-token', 'expires_in' => 3600]),
        'api-m.sandbox.paypal.com/v2/checkout/orders/SHARED-ORDER-1' => Http::response($body),
    ]);

    $this->postJson(route('invoice-shares.paypal.orders', ['token' => $token]), [
        'idempotency_key' => (string) Str::uuid(),
    ])->assertOk()->assertJsonPath('status', 'pending')->assertJsonPath('payer_approved', true)
        ->assertJsonPath('approval_url', null);

    $this->assertDatabaseCount('payments', 1);
    $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'pending', 'paid_at' => null]);
    Http::assertNotSent(fn (ClientRequest $request): bool => $request->method() === 'POST'
        && str_ends_with($request->url(), '/v2/checkout/orders'));
});

test('guest capture verifies full PayPal order details when the capture response omits order metadata', function (bool $minimalResponse): void {
    Http::preventStrayRequests();
    $invoice = publicCheckoutInvoice(User::factory()->create());
    $token = publicCheckoutShare($invoice);
    $payment = $invoice->payments()->create([
        'paypal_order_id' => 'SHARED-ORDER-1', 'amount' => '100', 'currency' => 'USD', 'status' => 'pending',
        'idempotency_key' => (string) Str::uuid(), 'paypal_capture_request_id' => (string) Str::uuid(),
    ]);
    $fullBody = publicCheckoutCaptureBody($payment);
    $captureBody = $fullBody;
    unset($captureBody['purchase_units'][0]['amount'], $captureBody['purchase_units'][0]['custom_id']);
    if ($minimalResponse) {
        $captureBody = ['id' => 'SHARED-ORDER-1', 'status' => 'COMPLETED'];
    }
    Http::fake([
        'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'public-sandbox-token', 'expires_in' => 3600]),
        'api-m.sandbox.paypal.com/v2/checkout/orders/SHARED-ORDER-1/capture' => Http::response($captureBody, 201),
        'api-m.sandbox.paypal.com/v2/checkout/orders/SHARED-ORDER-1' => Http::response($fullBody),
    ]);

    $this->postJson(route('invoice-shares.paypal.capture', ['token' => $token, 'orderId' => 'SHARED-ORDER-1']))
        ->assertOk()->assertJsonPath('payment.status', 'completed')->assertJsonPath('invoice.remaining_balance', '0.00');

    $this->assertDatabaseCount('payments', 1);
    $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'completed', 'paypal_capture_id' => 'SHARED-CAPTURE-1']);
    Http::assertSent(fn (ClientRequest $request): bool => $request->method() === 'GET'
        && $request->url() === 'https://api-m.sandbox.paypal.com/v2/checkout/orders/SHARED-ORDER-1');
    Http::assertSentCount(3);
})->with(['capture representation without order metadata' => false, 'minimal capture response' => true]);

test('full PayPal order details must match the stored order and original capture before completion', function (string $path, mixed $value): void {
    Http::preventStrayRequests();
    $invoice = publicCheckoutInvoice(User::factory()->create());
    $token = publicCheckoutShare($invoice);
    $payment = $invoice->payments()->create([
        'paypal_order_id' => 'SHARED-ORDER-1', 'amount' => '100', 'currency' => 'USD', 'status' => 'pending',
        'idempotency_key' => (string) Str::uuid(), 'paypal_capture_request_id' => (string) Str::uuid(),
    ]);
    $captureBody = publicCheckoutCaptureBody($payment);
    unset($captureBody['purchase_units'][0]['amount'], $captureBody['purchase_units'][0]['custom_id']);
    $fullBody = publicCheckoutCaptureBody($payment);
    data_set($fullBody, $path, $value);
    Http::fake([
        'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'public-sandbox-token', 'expires_in' => 3600]),
        'api-m.sandbox.paypal.com/v2/checkout/orders/SHARED-ORDER-1/capture' => Http::response($captureBody, 201),
        'api-m.sandbox.paypal.com/v2/checkout/orders/SHARED-ORDER-1' => Http::response($fullBody),
    ]);

    $this->postJson(route('invoice-shares.paypal.capture', ['token' => $token, 'orderId' => 'SHARED-ORDER-1']))->assertStatus(502);

    $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'pending', 'paid_at' => null, 'paypal_capture_id' => null]);
    $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'status' => 'approved']);
    Http::assertSentCount(3);
})->with([
    'different order id' => ['id', 'WRONG-ORDER-1'],
    'different capture id' => ['purchase_units.0.payments.captures.0.id', 'WRONG-CAPTURE-1'],
    'different verified capture amount' => ['purchase_units.0.payments.captures.0.amount.value', '99.00'],
]);

test('fetching PayPal order metadata never replaces a mismatched original capture amount', function (): void {
    Http::preventStrayRequests();
    $invoice = publicCheckoutInvoice(User::factory()->create());
    $token = publicCheckoutShare($invoice);
    $payment = $invoice->payments()->create([
        'paypal_order_id' => 'SHARED-ORDER-1', 'amount' => '100', 'currency' => 'USD', 'status' => 'pending',
        'idempotency_key' => (string) Str::uuid(), 'paypal_capture_request_id' => (string) Str::uuid(),
    ]);
    $captureBody = publicCheckoutCaptureBody($payment, '99.00');
    unset($captureBody['purchase_units'][0]['amount'], $captureBody['purchase_units'][0]['custom_id']);
    Http::fake([
        'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'public-sandbox-token', 'expires_in' => 3600]),
        'api-m.sandbox.paypal.com/v2/checkout/orders/SHARED-ORDER-1/capture' => Http::response($captureBody, 201),
        'api-m.sandbox.paypal.com/v2/checkout/orders/SHARED-ORDER-1' => Http::response(publicCheckoutCaptureBody($payment)),
    ]);

    $this->postJson(route('invoice-shares.paypal.capture', ['token' => $token, 'orderId' => 'SHARED-ORDER-1']))->assertStatus(502);

    $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'pending', 'paid_at' => null, 'paypal_capture_id' => null]);
    $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'status' => 'approved']);
    Http::assertSentCount(3);
});
