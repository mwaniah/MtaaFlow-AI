<?php

use App\Actions\AI\AskAccountContext;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/**
 * @param  array<string, mixed>  $attributes
 */
function askContextInvoice(User $user, array $attributes = []): Invoice
{
    $customer = $user->customers()->create([
        'name' => 'Visible Client',
        'email' => 'private-client@example.test',
        'phone' => '+254700000001',
        'address' => 'Private customer address',
    ]);

    return $user->invoices()->create([
        'customer_id' => $customer->id,
        'invoice_number' => 'ASK-'.str()->uuid(),
        'currency' => 'USD',
        'subtotal' => '100.00',
        'total' => '100.00',
        'status' => 'approved',
        'issue_date' => '2026-11-01',
        'due_date' => '2026-11-30',
        ...$attributes,
    ]);
}

test('customer counts are concise with correct grammar and no provider request', function (int $count, string $answer) {
    Http::preventStrayRequests();
    $user = User::factory()->create();

    for ($index = 1; $index <= $count; $index++) {
        $user->customers()->create(['name' => 'Customer '.$index]);
    }

    User::factory()->create()->customers()->create(['name' => 'Another account customer']);

    $result = app(AskAccountContext::class)->read($user, 'customers');

    expect($result)->toBe(['answer' => $answer, 'data' => ['query' => 'customers', 'count' => $count]]);
    Http::assertNothingSent();
})->with([
    'empty' => [0, 'You currently have 0 customers in your MtaaFlow account.'],
    'one' => [1, 'You currently have 1 customer in your MtaaFlow account.'],
    'two' => [2, 'You currently have 2 customers in your MtaaFlow account.'],
]);

test('customer lists expose only scoped names and report truthful totals beyond the list limit', function () {
    $user = User::factory()->create();

    for ($index = 1; $index <= 51; $index++) {
        $user->customers()->create([
            'name' => sprintf('Customer %02d', $index),
            'email' => 'contact-'.$index.'@example.test',
            'phone' => '+254700000001',
            'address' => 'Private address',
        ]);
    }

    User::factory()->create()->customers()->create(['name' => 'Other owner', 'email' => 'other@example.test']);

    $result = app(AskAccountContext::class)->read($user, 'customers', ['mode' => 'list', 'limit' => 2]);

    expect($result['data'])->toBe([
        'query' => 'customers', 'count' => 51,
        'customers' => [['name' => 'Customer 01'], ['name' => 'Customer 02']],
    ]);
    expect($result['answer'])->toContain('51 customers', '- Customer 01', 'Showing 2 of 51 matching records.')
        ->not->toContain('Other owner', '@example.test', '+254', 'Private address');
});

test('customer names cannot inject Markdown links HTML or additional answer lines', function () {
    $user = User::factory()->create();
    $user->customers()->create(['name' => "[Client](https://example.test) <img src=x onerror=alert(1)>\n# Heading **bold**"]);

    $result = app(AskAccountContext::class)->read($user, 'customers', ['mode' => 'list']);

    expect($result['answer'])->toContain('\\[Client\\]', '\\# Heading', '\\*\\*bold\\*\\*')
        ->not->toContain('<img', "\n# Heading", '[Client](');
});

test('invoice reads preserve exact balances and ignore pending failed and currency-mismatched payments', function () {
    $user = User::factory()->create();
    $invoice = askContextInvoice($user, ['invoice_number' => 'VISIBLE-INVOICE', 'total' => '100.00']);
    $invoice->payments()->create(['amount' => '25.25', 'currency' => 'USD', 'status' => 'completed']);
    $invoice->payments()->create(['amount' => '74.75', 'currency' => 'USD', 'status' => 'pending']);
    $invoice->payments()->create(['amount' => '74.75', 'currency' => 'USD', 'status' => 'failed']);
    $invoice->payments()->create(['amount' => '100.00', 'currency' => 'KES', 'status' => 'completed']);
    askContextInvoice($user, ['invoice_number' => 'DRAFT-INVOICE', 'status' => 'draft']);
    $paid = askContextInvoice($user, ['invoice_number' => 'FULLY-PAID']);
    $paid->payments()->create(['amount' => '100.00', 'currency' => 'USD', 'status' => 'completed']);
    askContextInvoice(User::factory()->create(), ['invoice_number' => 'OTHER-OWNER']);

    $result = app(AskAccountContext::class)->read($user, 'outstanding_invoices');

    expect($result['data']['count'])->toBe(1);
    expect($result['data']['invoices'][0])->toMatchArray([
        'invoice_number' => 'VISIBLE-INVOICE', 'currency' => 'USD', 'total' => '100.00',
        'paid' => '25.25', 'remaining_balance' => '74.75',
    ]);
    expect($result['answer'])->toContain('1 approved invoice', 'USD 74.75 outstanding', 'Visible Client')
        ->not->toContain('OTHER-OWNER', 'DRAFT-INVOICE', 'FULLY-PAID', 'private-client@example.test', '+254700000001', 'Private customer address');
});

test('invoice date filters use issue dates and keep all invoice statuses available', function () {
    $user = User::factory()->create();
    askContextInvoice($user, ['invoice_number' => 'OCTOBER-DRAFT', 'status' => 'draft', 'issue_date' => '2026-10-01', 'created_at' => '2026-11-10 10:00:00']);
    askContextInvoice($user, ['invoice_number' => 'OCTOBER-PAID', 'status' => 'paid', 'issue_date' => '2026-10-31']);
    askContextInvoice($user, ['invoice_number' => 'NOVEMBER', 'issue_date' => '2026-11-01', 'created_at' => '2026-10-15 10:00:00']);

    $result = app(AskAccountContext::class)->read($user, 'invoices', ['since' => '2026-10-01', 'until' => '2026-10-31']);

    expect($result['data']['count'])->toBe(2);
    expect($result['answer'])->toContain('OCTOBER-DRAFT', 'OCTOBER-PAID', '2026-10-01 to 2026-10-31')->not->toContain('NOVEMBER');
    $drafts = app(AskAccountContext::class)->read($user, 'invoices', ['status' => 'draft', 'mode' => 'count']);
    expect($drafts['answer'])->toBe('You have 1 draft invoice.');
});

test('completed fractional payments do not leave a fabricated outstanding balance', function () {
    $user = User::factory()->create();
    $paid = askContextInvoice($user, ['invoice_number' => 'DECIMAL-PAID', 'total' => '1.00']);

    for ($index = 0; $index < 10; $index++) {
        $paid->payments()->create(['amount' => '0.10', 'currency' => 'USD', 'status' => 'completed']);
    }

    askContextInvoice($user, ['invoice_number' => 'UNPAID-ONE', 'total' => '3.00']);
    askContextInvoice($user, ['invoice_number' => 'UNPAID-TWO', 'total' => '4.00']);

    $result = app(AskAccountContext::class)->read($user, 'outstanding_invoices', ['limit' => 1]);

    expect($result['data']['count'])->toBe(2);
    expect($result['data']['invoices'])->toHaveCount(1);
    expect($result['answer'])->toContain('2 approved invoices', 'Showing 1 of 2 matching records.')->not->toContain('DECIMAL-PAID');
    $count = app(AskAccountContext::class)->read($user, 'outstanding_invoices', ['mode' => 'count']);
    expect($count)->toBe([
        'answer' => 'You have 2 approved invoices with an outstanding balance.',
        'data' => ['query' => 'outstanding_invoices', 'count' => 2, 'invoices' => []],
    ]);
});

test('earnings include only owned completed matching-currency payments and keep original precision', function () {
    $user = User::factory()->create();
    $usd = askContextInvoice($user);
    $usd->payments()->create(['amount' => '0.10', 'currency' => 'USD', 'status' => 'completed']);
    $usd->payments()->create(['amount' => '0.20', 'currency' => 'USD', 'status' => 'completed']);
    $usd->payments()->create(['amount' => '999.00', 'currency' => 'USD', 'status' => 'pending']);
    $usd->payments()->create(['amount' => '888.00', 'currency' => 'USD', 'status' => 'failed']);
    $usd->payments()->create(['amount' => '777.00', 'currency' => 'KES', 'status' => 'completed']);
    $jpy = askContextInvoice($user, ['currency' => 'JPY']);
    $jpy->payments()->create(['amount' => '1234', 'currency' => 'JPY', 'status' => 'completed']);
    $bhd = askContextInvoice($user, ['currency' => 'BHD']);
    $bhd->payments()->create(['amount' => '1.234', 'currency' => 'BHD', 'status' => 'completed']);
    $other = askContextInvoice(User::factory()->create());
    $other->payments()->create(['amount' => '5000.00', 'currency' => 'USD', 'status' => 'completed']);

    $result = app(AskAccountContext::class)->read($user, 'revenue');

    expect($result['data']['totals_by_currency'])->toBe([
        ['currency' => 'BHD', 'total' => '1.234'], ['currency' => 'JPY', 'total' => '1234'], ['currency' => 'USD', 'total' => '0.30'],
    ]);
    expect($result['answer'])->toBe('Your verified earnings from completed payments are BHD 1.234; JPY 1234; USD 0.30.');
});

test('earnings date filters are inclusive and use actual payment dates with a recorded-date fallback', function () {
    $user = User::factory()->create();
    $invoice = askContextInvoice($user);
    $invoice->payments()->create(['amount' => '10.00', 'currency' => 'USD', 'status' => 'completed', 'paid_at' => '2026-10-01 00:00:00', 'created_at' => '2026-11-10 00:00:00']);
    $invoice->payments()->create(['amount' => '20.00', 'currency' => 'USD', 'status' => 'completed', 'paid_at' => '2026-10-31 23:59:59']);
    $invoice->payments()->create(['amount' => '5.00', 'currency' => 'USD', 'status' => 'completed'])
        ->forceFill(['created_at' => '2026-10-15 10:00:00'])->save();
    $invoice->payments()->create(['amount' => '100.00', 'currency' => 'USD', 'status' => 'completed', 'paid_at' => '2026-11-01 00:00:00', 'created_at' => '2026-10-15 00:00:00']);

    $result = app(AskAccountContext::class)->read($user, 'revenue', ['since' => '2026-10-01', 'until' => '2026-10-31', 'currency' => 'USD']);

    expect($result['data']['totals_by_currency'])->toBe([['currency' => 'USD', 'total' => '35.00']]);
    expect($result['answer'])->toContain('from 2026-10-01 to 2026-10-31', 'USD 35.00');
});

test('empty filtered earnings identify the currency without hiding earnings in another currency', function () {
    $user = User::factory()->create();
    $invoice = askContextInvoice($user, ['currency' => 'EUR']);
    $invoice->payments()->create(['amount' => '80.00', 'currency' => 'EUR', 'status' => 'completed']);

    $result = app(AskAccountContext::class)->read($user, 'revenue', ['currency' => 'USD']);

    expect($result)->toBe([
        'answer' => 'You have no verified completed-payment earnings in USD.',
        'data' => ['query' => 'revenue', 'totals_by_currency' => []],
    ]);
});

test('recent payments show real statuses without exposing PayPal identifiers or contacts', function () {
    $user = User::factory()->create();
    $invoice = askContextInvoice($user, ['invoice_number' => 'RECENT-INV']);
    $invoice->payments()->create(['amount' => '12.50', 'currency' => 'USD', 'status' => 'pending', 'paypal_order_id' => 'PRIVATE-ORDER'])
        ->forceFill(['created_at' => '2026-10-03 00:00:00'])->save();
    $invoice->payments()->create(['amount' => '25.00', 'currency' => 'USD', 'status' => 'completed', 'paid_at' => '2026-10-02 12:00:00']);
    $other = askContextInvoice(User::factory()->create(), ['invoice_number' => 'PRIVATE-INVOICE']);
    $other->payments()->create(['amount' => '90.00', 'currency' => 'USD', 'status' => 'completed']);

    $result = app(AskAccountContext::class)->read($user, 'payments', ['since' => '2026-10-01', 'until' => '2026-10-31']);

    expect($result['data']['count'])->toBe(2);
    expect($result['data']['payments'][0])->toBe(['invoice_number' => 'RECENT-INV', 'currency' => 'USD', 'amount' => '12.50', 'status' => 'pending', 'date' => '2026-10-03']);
    expect($result['answer'])->toContain('USD 12.50', 'pending', 'USD 25.00', 'completed')
        ->not->toContain('PRIVATE-ORDER', 'PRIVATE-INVOICE', 'private-client@example.test');
});

test('low-stock products exclude other accounts deleted and adequately stocked products', function () {
    $user = User::factory()->create();
    $low = Product::factory()->for($user)->create(['name' => 'Paper', 'quantity' => 2, 'low_stock_threshold' => 2]);
    Product::factory()->for($user)->create(['name' => 'Ink', 'quantity' => 3, 'low_stock_threshold' => 2]);
    Product::factory()->for($user)->create(['name' => 'Deleted stock', 'quantity' => 0])->delete();
    Product::factory()->create(['name' => 'Other owner stock', 'quantity' => 0]);

    $result = app(AskAccountContext::class)->read($user, 'low_stock');

    expect($result['data'])->toBe(['query' => 'low_stock', 'count' => 1, 'products' => [['id' => $low->id, 'name' => 'Paper', 'quantity' => 2, 'low_stock_threshold' => 2]]]);
    expect($result['answer'])->toBe("You have 1 product at or below its low-stock threshold.\n\n- Paper — 2 in stock; low-stock threshold 2.");
});

test('reminder answers preserve delivery state without disclosing messages or another account', function () {
    $user = User::factory()->create();
    $invoice = askContextInvoice($user, ['invoice_number' => 'MY-REMINDER']);
    $invoice->reminders()->create(['message' => 'Private reminder contents', 'status' => 'draft']);
    $other = askContextInvoice(User::factory()->create(), ['invoice_number' => 'OTHER-REMINDER']);
    $other->reminders()->create(['message' => 'Other private message', 'status' => 'sent']);

    $result = app(AskAccountContext::class)->read($user, 'reminders');

    expect($result['data']['count'])->toBe(1);
    expect($result['answer'])->toContain('1 payment reminder', 'MY-REMINDER — draft')
        ->not->toContain('OTHER-REMINDER', 'Private reminder contents', 'Other private message');
});

test('empty business summaries state zero counts without inventing earnings or profit', function () {
    $user = User::factory()->create();
    $other = askContextInvoice(User::factory()->create());
    $other->payments()->create(['amount' => '10.00', 'currency' => 'USD', 'status' => 'completed']);

    $result = app(AskAccountContext::class)->read($user, 'summary');

    expect($result['data'])->toBe([
        'query' => 'summary', 'customers' => 0, 'total_invoices' => 0, 'paid_invoices' => 0,
        'outstanding_invoices' => 0, 'totals_by_currency' => [],
        'products' => 0, 'low_stock_products' => 0, 'approved_invoices' => 0, 'reminders' => 0,
    ]);
    expect($result['answer'])->toContain('0 customers', '0 invoices', 'no verified completed-payment earnings', 'not profit')
        ->not->toContain('USD 10.00', '\\n', 'totals_by_currency');
});

test('summary preserves inventory approval and reminder counts with ownership and date scopes', function () {
    $user = User::factory()->create();
    $invoice = askContextInvoice($user, ['issue_date' => '2026-10-15', 'currency' => 'USD']);
    askContextInvoice($user, ['issue_date' => '2026-11-01']);
    askContextInvoice($user, ['issue_date' => '2026-10-16', 'currency' => 'EUR']);
    $invoice->reminders()->create(['message' => 'Private reminder', 'status' => 'draft'])
        ->forceFill(['created_at' => '2026-10-20 12:00:00'])->save();
    $invoice->reminders()->create(['message' => 'Outside date range', 'status' => 'draft'])
        ->forceFill(['created_at' => '2026-11-01 00:00:00'])->save();
    Product::factory()->for($user)->create(['quantity' => 2, 'low_stock_threshold' => 2, 'created_at' => '2026-10-10 12:00:00']);
    Product::factory()->for($user)->create(['quantity' => 5, 'low_stock_threshold' => 2, 'created_at' => '2026-10-10 12:00:00']);
    Product::factory()->for($user)->create(['quantity' => 0, 'created_at' => '2026-11-01 00:00:00']);
    $other = User::factory()->create();
    Product::factory()->for($other)->create(['quantity' => 0, 'created_at' => '2026-10-10 12:00:00']);
    askContextInvoice($other, ['issue_date' => '2026-10-10'])->reminders()->create(['message' => 'Another owner reminder', 'status' => 'draft'])
        ->forceFill(['created_at' => '2026-10-20 12:00:00'])->save();

    $result = app(AskAccountContext::class)->read($user, 'summary', ['since' => '2026-10-01', 'until' => '2026-10-31', 'currency' => 'USD']);

    expect($result['data'])->toMatchArray([
        'total_invoices' => 1, 'approved_invoices' => 1, 'outstanding_invoices' => 1,
        'products' => 2, 'low_stock_products' => 1, 'reminders' => 1,
    ]);
    expect($result['answer'])->toContain('2 inventory products', '1 at or below its low-stock threshold', '1 recorded payment reminder')
        ->not->toContain('Private reminder', 'Outside date range', 'Another owner reminder');
});

test('unsafe or unsupported account scope options are rejected', function (string $query, array $options) {
    $user = User::factory()->create();

    expect(fn () => app(AskAccountContext::class)->read($user, $query, $options))->toThrow(ValidationException::class);
})->with([
    'unknown query' => ['raw_sql', []],
    'injected account owner' => ['customers', ['user_id' => 999]],
    'unbounded list' => ['customers', ['limit' => 21]],
    'zero list' => ['customers', ['limit' => 0]],
    'invalid date' => ['customers', ['since' => 'yesterday']],
    'reversed range' => ['invoices', ['since' => '2026-11-01', 'until' => '2026-10-31']],
    'unknown currency' => ['revenue', ['currency' => 'ZZZ']],
    'irrelevant currency scope' => ['customers', ['currency' => 'USD']],
    'untrusted status' => ['invoices', ['status' => "paid' OR 1=1"]],
    'failed earnings' => ['revenue', ['status' => 'failed']],
    'unknown mode' => ['customers', ['mode' => 'export']],
]);
