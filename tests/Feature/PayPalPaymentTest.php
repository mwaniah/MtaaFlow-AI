<?php

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;

function configurePayPalSandbox(): void
{
    config([
        'services.paypal.client_id' => 'sandbox-client',
        'services.paypal.client_secret' => 'sandbox-secret',
        'services.paypal.base_url' => 'https://api-m.sandbox.paypal.com',
    ]);
}

function createApprovedInvoice(User $user, string $currency = 'USD', string $total = '100'): Invoice
{
    $customer = $user->customers()->create(['name' => fake()->name()]);

    return $user->invoices()->create([
        'customer_id' => $customer->id,
        'invoice_number' => 'INV-PAYPAL-'.strtoupper(fake()->unique()->bothify('########')),
        'currency' => $currency,
        'subtotal' => $total,
        'total' => $total,
        'status' => 'approved',
        'issue_date' => '2026-11-07',
    ]);
}

test('PayPal order creation uses server-calculated amounts and is idempotent', function () {
    configurePayPalSandbox();
    Http::preventStrayRequests();
    Http::fake([
        'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response([
            'access_token' => 'sandbox-access-token',
            'expires_in' => 3600,
        ]),
        'api-m.sandbox.paypal.com/v2/checkout/orders' => Http::response([
            'id' => 'PAYPAL-ORDER-1',
            'status' => 'CREATED',
            'links' => [[
                'rel' => 'approve',
                'href' => 'https://www.sandbox.paypal.com/checkoutnow?token=PAYPAL-ORDER-1',
            ]],
        ], 201),
    ]);

    $user = User::factory()->create();
    $invoice = createApprovedInvoice($user);
    $idempotencyKey = 'a69fba86-cff4-4c3f-a2f0-04e6ee42b209';
    $payload = ['idempotency_key' => $idempotencyKey, 'amount' => '25.00'];

    $firstResponse = $this->actingAs($user)
        ->postJson(route('payments.paypal.orders', $invoice), $payload);
    $secondResponse = $this->postJson(route('payments.paypal.orders', $invoice), $payload);

    $firstResponse->assertOk()
        ->assertJsonPath('order_id', 'PAYPAL-ORDER-1')
        ->assertJsonPath('amount', '25.00')
        ->assertJsonPath('currency', 'USD')
        ->assertJsonPath('status', 'pending');
    $secondResponse->assertOk()->assertJsonPath('order_id', 'PAYPAL-ORDER-1');

    $this->assertDatabaseCount('payments', 1);
    $this->assertDatabaseHas('payments', [
        'invoice_id' => $invoice->id,
        'amount' => '25.0000',
        'currency' => 'USD',
        'status' => 'pending',
        'idempotency_key' => $idempotencyKey,
    ]);
    Http::assertSentCount(2);
    Http::assertSent(fn (ClientRequest $request): bool => str_ends_with($request->url(), '/v2/checkout/orders')
        && $request->header('PayPal-Request-Id')[0] === $idempotencyKey
        && $request['purchase_units'][0]['amount']['value'] === '25.00');
});

test('PayPal checkout rejects unsupported currencies and amounts above the balance', function () {
    configurePayPalSandbox();
    Http::preventStrayRequests();

    $user = User::factory()->create();
    $unsupportedInvoice = createApprovedInvoice($user, 'KES');
    $supportedInvoice = createApprovedInvoice($user, 'USD');

    $this->actingAs($user)
        ->postJson(route('payments.paypal.orders', $unsupportedInvoice), [
            'idempotency_key' => 'c4c0db73-0f65-45c5-91ae-46585b89ad0a',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('currency');

    $this->postJson(route('payments.paypal.orders', $supportedInvoice), [
        'idempotency_key' => '18fb35e5-7656-42f3-aa3d-77398b7ac4fb',
        'amount' => '100.01',
    ])->assertUnprocessable()->assertJsonValidationErrors('amount');

    $this->assertDatabaseCount('payments', 0);
});

test('verified PayPal captures record partial payments once and preserve the remaining balance', function () {
    configurePayPalSandbox();
    Http::preventStrayRequests();
    Http::fake([
        'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response([
            'access_token' => 'sandbox-access-token',
            'expires_in' => 3600,
        ]),
        'api-m.sandbox.paypal.com/v2/checkout/orders' => Http::response([
            'id' => 'PAYPAL-ORDER-2',
            'status' => 'CREATED',
            'links' => [[
                'rel' => 'approve',
                'href' => 'https://www.sandbox.paypal.com/checkoutnow?token=PAYPAL-ORDER-2',
            ]],
        ], 201),
        'api-m.sandbox.paypal.com/v2/checkout/orders/*/capture' => function (ClientRequest $request) {
            $payment = Payment::query()
                ->where('paypal_order_id', 'PAYPAL-ORDER-2')
                ->firstOrFail();

            return Http::response([
                'status' => 'COMPLETED',
                'purchase_units' => [[
                    'reference_id' => 'invoice-'.$payment->invoice_id,
                    'custom_id' => 'payment-'.$payment->id,
                    'amount' => ['currency_code' => 'USD', 'value' => '25.00'],
                    'payments' => ['captures' => [[
                        'id' => 'CAPTURE-2',
                        'status' => 'COMPLETED',
                        'amount' => ['currency_code' => 'USD', 'value' => '25.00'],
                    ]]],
                ]],
            ], 201);
        },
    ]);

    $user = User::factory()->create();
    $invoice = createApprovedInvoice($user);
    $idempotencyKey = '5f22e80b-96f6-4f4a-94cc-3e9abf0f8e98';

    $order = $this->actingAs($user)->postJson(route('payments.paypal.orders', $invoice), [
        'idempotency_key' => $idempotencyKey,
        'amount' => '25.00',
    ])->assertOk();

    $captureRoute = route('payments.paypal.capture', ['orderId' => $order->json('order_id')]);
    $capture = $this->postJson($captureRoute);

    $capture->assertOk()
        ->assertJsonPath('payment.status', 'completed')
        ->assertJsonPath('payment.amount', '25.00')
        ->assertJsonPath('invoice.status', 'approved')
        ->assertJsonPath('invoice.remaining_balance', '75.00');

    $payment = Payment::query()->where('paypal_order_id', 'PAYPAL-ORDER-2')->firstOrFail();
    $this->assertDatabaseHas('payments', [
        'id' => $payment->id,
        'status' => 'completed',
        'paypal_capture_id' => 'CAPTURE-2',
    ]);
    $this->assertDatabaseHas('invoices', [
        'id' => $invoice->id,
        'status' => 'approved',
    ]);

    $this->postJson($captureRoute)->assertOk()->assertJsonPath('payment.id', $payment->id);
    Http::assertSentCount(3);
});

test('PayPal captures with a mismatched amount never complete a payment', function () {
    configurePayPalSandbox();
    Http::preventStrayRequests();
    Http::fake([
        'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response([
            'access_token' => 'sandbox-access-token',
            'expires_in' => 3600,
        ]),
        'api-m.sandbox.paypal.com/v2/checkout/orders' => Http::response([
            'id' => 'PAYPAL-ORDER-3',
            'status' => 'CREATED',
            'links' => [[
                'rel' => 'approve',
                'href' => 'https://www.sandbox.paypal.com/checkoutnow?token=PAYPAL-ORDER-3',
            ]],
        ], 201),
        'api-m.sandbox.paypal.com/v2/checkout/orders/*/capture' => function () {
            $payment = Payment::query()
                ->where('paypal_order_id', 'PAYPAL-ORDER-3')
                ->firstOrFail();

            return Http::response([
                'status' => 'COMPLETED',
                'purchase_units' => [[
                    'reference_id' => 'invoice-'.$payment->invoice_id,
                    'custom_id' => 'payment-'.$payment->id,
                    'amount' => ['currency_code' => 'USD', 'value' => '24.00'],
                    'payments' => ['captures' => [[
                        'id' => 'CAPTURE-3',
                        'status' => 'COMPLETED',
                        'amount' => ['currency_code' => 'USD', 'value' => '24.00'],
                    ]]],
                ]],
            ], 201);
        },
    ]);

    $user = User::factory()->create();
    $invoice = createApprovedInvoice($user);
    $order = $this->actingAs($user)->postJson(route('payments.paypal.orders', $invoice), [
        'idempotency_key' => 'dd8feb0f-1f54-44f5-9d00-a12bb6a2050b',
        'amount' => '25.00',
    ])->assertOk();

    $this->postJson(route('payments.paypal.capture', ['orderId' => $order->json('order_id')]))
        ->assertStatus(502)
        ->assertJsonPath('message', 'PayPal could not verify the captured payment.');

    $this->assertDatabaseHas('payments', [
        'invoice_id' => $invoice->id,
        'status' => 'pending',
        'paypal_capture_id' => null,
    ]);
    $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'status' => 'approved']);
});

test('PayPal order capture is scoped to the invoice owner', function () {
    configurePayPalSandbox();
    Http::preventStrayRequests();
    $owner = User::factory()->create();
    $invoice = createApprovedInvoice($owner);
    $invoice->payments()->create([
        'paypal_order_id' => 'PRIVATE-ORDER-1',
        'paypal_capture_request_id' => '13dd338a-cc8b-48d0-b181-4b5737fc97b2',
        'idempotency_key' => 'a3a3149f-7c7c-415f-9349-2b36fd2a7f11',
        'amount' => '20.00',
        'currency' => 'USD',
        'status' => 'pending',
    ]);

    $this->actingAs(User::factory()->create())
        ->postJson(route('payments.paypal.capture', ['orderId' => 'PRIVATE-ORDER-1']))
        ->assertNotFound();
});
