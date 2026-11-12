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
        'services.paypal.mode' => 'sandbox',
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
        'api-m.sandbox.paypal.com/v2/checkout/orders/PAYPAL-ORDER-1' => Http::response([
            'id' => 'PAYPAL-ORDER-1',
            'status' => 'CREATED',
            'links' => [[
                'rel' => 'approve',
                'href' => 'https://www.sandbox.paypal.com/checkoutnow?token=PAYPAL-ORDER-1',
            ]],
        ]),
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
    Http::assertSentCount(3);
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
                'id' => 'PAYPAL-ORDER-2',
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
                'id' => 'PAYPAL-ORDER-3',
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

test('PayPal order JSON preserves the exact approval URL returned by PayPal', function (): void {
    configurePayPalSandbox();
    Http::preventStrayRequests();
    $approvalUrl = 'https://www.sandbox.paypal.com/checkoutnow?token=PAYPAL-APPROVAL-1&useraction=commit&locale.x=fr_FR&context=one%2Btwo%2Fthree';
    Http::fake([
        'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response([
            'access_token' => 'sandbox-access-token', 'expires_in' => 3600,
        ]),
        'api-m.sandbox.paypal.com/v2/checkout/orders' => Http::response([
            'id' => 'PAYPAL-APPROVAL-1',
            'status' => 'PAYER_ACTION_REQUIRED',
            'links' => [['rel' => 'payer-action', 'href' => $approvalUrl]],
        ], 201),
    ]);
    $user = User::factory()->create();
    $invoice = createApprovedInvoice($user);

    $this->actingAs($user)->postJson(route('payments.paypal.orders', $invoice), [
        'idempotency_key' => '6e8db656-15d8-4856-84d7-d50e413d039f',
    ])->assertOk()
        ->assertHeader('Content-Type', 'application/json')
        ->assertJsonPath('order_id', 'PAYPAL-APPROVAL-1')
        ->assertJsonPath('approval_url', $approvalUrl);

    $this->assertDatabaseHas('payments', [
        'invoice_id' => $invoice->id,
        'paypal_order_id' => 'PAYPAL-APPROVAL-1',
        'paypal_approval_url' => $approvalUrl,
        'status' => 'pending',
    ]);
    Http::assertSentCount(2);
});

test('PayPal Sandbox never returns an approval URL for a different environment host or order', function (string $approvalUrl): void {
    configurePayPalSandbox();
    Http::preventStrayRequests();
    Http::fake([
        'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response([
            'access_token' => 'sandbox-access-token', 'expires_in' => 3600,
        ]),
        'api-m.sandbox.paypal.com/v2/checkout/orders' => Http::response([
            'id' => 'PAYPAL-APPROVAL-1',
            'status' => 'CREATED',
            'links' => [['rel' => 'approve', 'href' => $approvalUrl]],
        ], 201),
    ]);
    $user = User::factory()->create();
    $invoice = createApprovedInvoice($user);

    $this->actingAs($user)->postJson(route('payments.paypal.orders', $invoice), [
        'idempotency_key' => 'fc4a85c2-0fca-499e-a8fe-08a065f1c10e',
    ])->assertStatus(502)->assertJsonMissingPath('approval_url');

    $this->assertDatabaseCount('payments', 1);
    $this->assertDatabaseHas('payments', [
        'invoice_id' => $invoice->id,
        'paypal_order_id' => null,
        'paypal_approval_url' => null,
        'status' => 'pending',
        'paid_at' => null,
    ]);
    Http::assertSentCount(2);
})->with([
    'Live approval URL' => 'https://www.paypal.com/checkoutnow?token=PAYPAL-APPROVAL-1',
    'untrusted host' => 'https://www.sandbox.paypal.com.example.test/checkoutnow?token=PAYPAL-APPROVAL-1',
    'different order token' => 'https://www.sandbox.paypal.com/checkoutnow?token=OTHER-ORDER-1',
    'insecure approval URL' => 'http://www.sandbox.paypal.com/checkoutnow?token=PAYPAL-APPROVAL-1',
]);

test('PayPal capture verifies the exact order and a single purchase unit and capture before completion', function (string $defect): void {
    configurePayPalSandbox();
    Http::preventStrayRequests();
    $user = User::factory()->create();
    $invoice = createApprovedInvoice($user);
    $payment = $invoice->payments()->create([
        'paypal_order_id' => 'PAYPAL-VERIFY-1',
        'paypal_capture_request_id' => 'd29d8abd-b082-4a05-9b3d-04a1857cdcc9',
        'idempotency_key' => '9ea0b384-59c8-48e7-9f66-e5cd8dc52b65',
        'amount' => '100.00', 'currency' => 'USD', 'status' => 'pending',
    ]);
    $captureBody = [
        'id' => 'PAYPAL-VERIFY-1',
        'status' => 'COMPLETED',
        'purchase_units' => [[
            'reference_id' => 'invoice-'.$invoice->id,
            'custom_id' => 'payment-'.$payment->id,
            'amount' => ['currency_code' => 'USD', 'value' => '100.00'],
            'payments' => ['captures' => [[
                'id' => 'PAYPAL-CAPTURE-VERIFY-1',
                'status' => 'COMPLETED',
                'amount' => ['currency_code' => 'USD', 'value' => '100.00'],
            ]]],
        ]],
    ];
    if ($defect === 'wrong order') {
        $captureBody['id'] = 'OTHER-ORDER-1';
    } elseif ($defect === 'missing order') {
        unset($captureBody['id']);
    } elseif ($defect === 'malformed purchase units') {
        $captureBody['purchase_units'] = 'invalid-provider-data';
    } elseif ($defect === 'extra purchase unit') {
        $captureBody['purchase_units'][] = $captureBody['purchase_units'][0];
    } else {
        $captureBody['purchase_units'][0]['payments']['captures'][] = $captureBody['purchase_units'][0]['payments']['captures'][0];
    }
    Http::fake([
        'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response([
            'access_token' => 'sandbox-access-token', 'expires_in' => 3600,
        ]),
        'api-m.sandbox.paypal.com/v2/checkout/orders/PAYPAL-VERIFY-1/capture' => Http::response($captureBody, 201),
        'api-m.sandbox.paypal.com/v2/checkout/orders/PAYPAL-VERIFY-1' => Http::response($captureBody),
    ]);

    $this->actingAs($user)
        ->postJson(route('payments.paypal.capture', ['orderId' => 'PAYPAL-VERIFY-1']))
        ->assertStatus(502)
        ->assertJsonPath('message', 'PayPal could not verify the captured payment.');

    $this->assertDatabaseHas('payments', [
        'id' => $payment->id, 'status' => 'pending', 'paypal_capture_id' => null, 'paid_at' => null,
    ]);
    $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'status' => 'approved']);
    Http::assertSentCount($defect === 'malformed purchase units' ? 3 : 2);
})->with(['wrong order', 'missing order', 'malformed purchase units', 'extra purchase unit', 'extra capture']);

test('PayPal rejects inconsistent API environments before sending merchant credentials', function (string $mode, string $baseUrl): void {
    configurePayPalSandbox();
    config(['services.paypal.mode' => $mode, 'services.paypal.base_url' => $baseUrl]);
    Http::preventStrayRequests();
    $user = User::factory()->create();
    $invoice = createApprovedInvoice($user);

    $this->actingAs($user)->postJson(route('payments.paypal.orders', $invoice), [
        'idempotency_key' => '82bb8792-2c0c-446e-a2b7-dc21029ef80a',
    ])->assertStatus(503);

    $this->assertDatabaseCount('payments', 0);
    Http::assertNothingSent();
})->with([
    'Sandbox mode with Live API' => ['sandbox', 'https://api-m.paypal.com'],
    'Live mode with Sandbox API' => ['live', 'https://api-m.sandbox.paypal.com'],
    'Sandbox mode with untrusted API' => ['sandbox', 'https://payments.example.test'],
]);

test('PayPal already captured responses reconcile verified order details exactly once', function (): void {
    configurePayPalSandbox();
    Http::preventStrayRequests();
    $user = User::factory()->create();
    $invoice = createApprovedInvoice($user);
    $payment = $invoice->payments()->create([
        'paypal_order_id' => 'PAYPAL-RECOVER-1',
        'paypal_capture_request_id' => '01da9f90-0b01-4687-8485-72016bf0e4f1',
        'idempotency_key' => '76f940bd-c5d6-459f-9a14-30c519dc6e6f',
        'amount' => '100.00', 'currency' => 'USD', 'status' => 'pending',
    ]);
    Http::fake([
        'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response([
            'access_token' => 'sandbox-access-token', 'expires_in' => 3600,
        ]),
        'api-m.sandbox.paypal.com/v2/checkout/orders/PAYPAL-RECOVER-1/capture' => Http::response([
            'name' => 'UNPROCESSABLE_ENTITY',
            'details' => [['issue' => 'ORDER_ALREADY_CAPTURED']],
        ], 422),
        'api-m.sandbox.paypal.com/v2/checkout/orders/PAYPAL-RECOVER-1' => Http::response([
            'id' => 'PAYPAL-RECOVER-1',
            'status' => 'COMPLETED',
            'purchase_units' => [[
                'reference_id' => 'invoice-'.$invoice->id,
                'custom_id' => 'payment-'.$payment->id,
                'amount' => ['currency_code' => 'USD', 'value' => '100.00'],
                'payments' => ['captures' => [[
                    'id' => 'PAYPAL-RECOVER-CAPTURE-1',
                    'status' => 'COMPLETED',
                    'amount' => ['currency_code' => 'USD', 'value' => '100.00'],
                ]]],
            ]],
        ]),
    ]);

    $captureRoute = route('payments.paypal.capture', ['orderId' => 'PAYPAL-RECOVER-1']);
    $this->actingAs($user)->postJson($captureRoute)->assertOk()
        ->assertJsonPath('payment.id', $payment->id)
        ->assertJsonPath('payment.status', 'completed')
        ->assertJsonPath('invoice.status', 'paid')
        ->assertJsonPath('invoice.remaining_balance', '0.00');
    $this->postJson($captureRoute)->assertOk()->assertJsonPath('payment.id', $payment->id);

    $this->assertDatabaseCount('payments', 1);
    $this->assertDatabaseHas('payments', [
        'id' => $payment->id, 'status' => 'completed', 'paypal_capture_id' => 'PAYPAL-RECOVER-CAPTURE-1',
    ]);
    expect($payment->refresh()->paid_at)->not->toBeNull();
    $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'status' => 'paid']);
    Http::assertSent(fn (ClientRequest $request): bool => $request->method() === 'GET'
        && $request->url() === 'https://api-m.sandbox.paypal.com/v2/checkout/orders/PAYPAL-RECOVER-1');
    Http::assertSentCount(3);
});

test('PayPal never records the same verified capture for two payments', function (): void {
    configurePayPalSandbox();
    Http::preventStrayRequests();
    $user = User::factory()->create();
    $invoice = createApprovedInvoice($user);
    $payment = $invoice->payments()->create([
        'paypal_order_id' => 'PAYPAL-DUPLICATE-1',
        'paypal_capture_request_id' => 'fc1f2dbb-7a91-4771-a0c3-e9ffdcf3b3cb',
        'idempotency_key' => '068140f2-07a3-43c0-985a-15431443b5dc',
        'amount' => '100.00', 'currency' => 'USD', 'status' => 'pending',
    ]);
    $otherInvoice = createApprovedInvoice($user);
    $recordedPayment = $otherInvoice->payments()->create([
        'paypal_capture_id' => 'PAYPAL-EXISTING-CAPTURE-1',
        'amount' => '100.00', 'currency' => 'USD', 'status' => 'completed', 'paid_at' => now(),
    ]);
    Http::fake([
        'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response([
            'access_token' => 'sandbox-access-token', 'expires_in' => 3600,
        ]),
        'api-m.sandbox.paypal.com/v2/checkout/orders/PAYPAL-DUPLICATE-1/capture' => Http::response([
            'id' => 'PAYPAL-DUPLICATE-1',
            'status' => 'COMPLETED',
            'purchase_units' => [[
                'reference_id' => 'invoice-'.$invoice->id,
                'custom_id' => 'payment-'.$payment->id,
                'amount' => ['currency_code' => 'USD', 'value' => '100.00'],
                'payments' => ['captures' => [[
                    'id' => 'PAYPAL-EXISTING-CAPTURE-1',
                    'status' => 'COMPLETED',
                    'amount' => ['currency_code' => 'USD', 'value' => '100.00'],
                ]]],
            ]],
        ], 201),
    ]);

    $this->actingAs($user)->postJson(route('payments.paypal.capture', ['orderId' => 'PAYPAL-DUPLICATE-1']))
        ->assertConflict()->assertJsonPath('message', 'This PayPal capture was already recorded.');

    $this->assertDatabaseCount('payments', 2);
    $this->assertDatabaseHas('payments', [
        'id' => $payment->id, 'status' => 'pending', 'paypal_capture_id' => null, 'paid_at' => null,
    ]);
    $this->assertDatabaseHas('payments', [
        'id' => $recordedPayment->id, 'status' => 'completed', 'paypal_capture_id' => 'PAYPAL-EXISTING-CAPTURE-1',
    ]);
    $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'status' => 'approved']);
    Http::assertSentCount(2);
});

test('PayPal rechecks the remaining invoice balance before charging an existing checkout', function (): void {
    configurePayPalSandbox();
    Http::preventStrayRequests();
    $user = User::factory()->create();
    $invoice = createApprovedInvoice($user);
    $payment = $invoice->payments()->create([
        'paypal_order_id' => 'PAYPAL-BALANCE-1',
        'paypal_capture_request_id' => '77cf5324-7efb-497f-8208-c190a0077f03',
        'idempotency_key' => '11f1ca49-63a1-4088-9760-6f6bfa78bf07',
        'amount' => '100.00', 'currency' => 'USD', 'status' => 'pending',
    ]);
    $previousPayment = $invoice->payments()->create([
        'amount' => '25.00', 'currency' => 'USD', 'status' => 'completed', 'paid_at' => now(),
    ]);

    $this->actingAs($user)->postJson(route('payments.paypal.capture', ['orderId' => 'PAYPAL-BALANCE-1']))
        ->assertUnprocessable()->assertJsonValidationErrors('amount');

    $this->assertDatabaseHas('payments', [
        'id' => $payment->id, 'status' => 'pending', 'paypal_capture_id' => null, 'paid_at' => null,
    ]);
    $this->assertDatabaseHas('payments', [
        'id' => $previousPayment->id, 'amount' => '25.0000', 'status' => 'completed',
    ]);
    $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'status' => 'approved']);
    Http::assertNothingSent();
});

test('PayPal replaces an unavailable checkout using the reduced remaining balance', function (): void {
    configurePayPalSandbox();
    Http::preventStrayRequests();
    $user = User::factory()->create();
    $invoice = createApprovedInvoice($user);
    $unavailablePayment = $invoice->payments()->create([
        'paypal_order_id' => 'PAYPAL-UNAVAILABLE-100',
        'paypal_capture_request_id' => '6a624871-1fe9-4b98-9898-c24c8f721014',
        'idempotency_key' => 'ee696254-0de2-4932-9c48-c12e314d73c2',
        'amount' => '100.00', 'currency' => 'USD', 'status' => 'pending',
    ]);
    $completedPayment = $invoice->payments()->create([
        'amount' => '25.00', 'currency' => 'USD', 'status' => 'completed', 'paid_at' => now(),
    ]);
    $approvalUrl = 'https://www.sandbox.paypal.com/checkoutnow?token=PAYPAL-REPLACEMENT-75&useraction=commit';
    Http::fake([
        'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response([
            'access_token' => 'sandbox-access-token', 'expires_in' => 3600,
        ]),
        'api-m.sandbox.paypal.com/v2/checkout/orders/PAYPAL-UNAVAILABLE-100' => Http::response([
            'name' => 'RESOURCE_NOT_FOUND',
            'details' => [['issue' => 'INVALID_RESOURCE_ID']],
        ], 404),
        'api-m.sandbox.paypal.com/v2/checkout/orders' => Http::response([
            'id' => 'PAYPAL-REPLACEMENT-75',
            'status' => 'CREATED',
            'links' => [['rel' => 'approve', 'href' => $approvalUrl]],
        ], 201),
    ]);
    $idempotencyKey = 'dfe6f6d7-4ed9-46aa-a0af-1d13d587844b';

    $this->actingAs($user)->postJson(route('payments.paypal.orders', $invoice), [
        'idempotency_key' => $idempotencyKey,
    ])->assertOk()
        ->assertJsonPath('order_id', 'PAYPAL-REPLACEMENT-75')
        ->assertJsonPath('amount', '75.00')
        ->assertJsonPath('approval_url', $approvalUrl)
        ->assertJsonPath('status', 'pending');

    $this->assertDatabaseCount('payments', 3);
    $this->assertDatabaseHas('payments', [
        'id' => $unavailablePayment->id, 'paypal_order_id' => 'PAYPAL-UNAVAILABLE-100',
        'amount' => '100.0000', 'status' => 'failed', 'paid_at' => null,
    ]);
    $this->assertDatabaseHas('payments', [
        'id' => $completedPayment->id, 'amount' => '25.0000', 'status' => 'completed',
    ]);
    $this->assertDatabaseHas('payments', [
        'invoice_id' => $invoice->id, 'paypal_order_id' => 'PAYPAL-REPLACEMENT-75',
        'amount' => '75.0000', 'status' => 'pending', 'idempotency_key' => $idempotencyKey,
    ]);
    expect($invoice->payments()->where('status', 'pending')->count())->toBe(1);
    $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'status' => 'approved']);
    Http::assertSent(fn (ClientRequest $request): bool => $request->method() === 'POST'
        && str_ends_with($request->url(), '/v2/checkout/orders')
        && $request['purchase_units'][0]['amount'] === ['currency_code' => 'USD', 'value' => '75.00']
        && $request->hasHeader('PayPal-Request-Id', $idempotencyKey));
    Http::assertSentCount(3);
});
