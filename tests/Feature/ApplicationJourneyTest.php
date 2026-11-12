<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia;

test('a new account can persist its customer invoice stock reminder and share journey', function () {
    $this->post(route('register.store'), [
        'name' => 'Journey Owner', 'email' => 'journey@example.test', 'password' => 'password', 'password_confirmation' => 'password',
    ])->assertRedirect(route('dashboard', absolute: false));
    $user = User::query()->where('email', 'journey@example.test')->firstOrFail();
    $this->assertAuthenticatedAs($user);
    expect($user->email_verified_at)->toBeNull();
    $this->get(route('dashboard'))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('totalCustomers', 0)->where('totalInvoices', 0)->where('verifiedEarningsByCurrency', [])->where('invoicedTotalsByCurrency', []));

    $this->post(route('customers.store'), ['name' => 'Journey Customer', 'email' => 'customer@example.test', 'phone' => '+254700000000'])->assertRedirect(route('customers.index'));
    $customer = $user->customers()->firstOrFail();
    $this->put(route('customers.update', $customer), ['name' => 'Updated customer'])->assertRedirect(route('customers.index'));
    $this->get(route('customers.show', $customer))->assertInertia(fn (AssertableInertia $page) => $page->where('customer.name', 'Updated customer'));

    $data = ['customer_id' => $customer->id, 'currency' => 'USD', 'issue_date' => '2026-11-10', 'due_date' => '2026-11-24', 'items' => [['description' => 'Design', 'quantity' => 2, 'unit_price' => '25.00']]];
    $this->post(route('invoices.store'), $data)->assertRedirect(route('invoices.index'));
    $invoice = $user->invoices()->firstOrFail();
    $data['items'][0]['quantity'] = 3;
    $this->put(route('invoices.update', $invoice), $data)->assertRedirect(route('invoices.index'));
    $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'total' => '75.00', 'status' => 'draft']);
    $this->post(route('invoices.approve', $invoice))->assertRedirect(route('invoices.show', $invoice));
    $this->get(route('invoices.index'))->assertInertia(fn (AssertableInertia $page) => $page->where('invoices.0.invoice_number', $invoice->invoice_number)->where('invoices.0.status', 'approved'));
    $this->get(route('dashboard'))->assertInertia(fn (AssertableInertia $page) => $page->where('invoicedTotalsByCurrency.0.currency', 'USD')->where('invoicedTotalsByCurrency.0.total', '75.0000'));
    $share = $this->postJson(route('invoices.shares.store', $invoice), [])->assertCreated();
    $this->getJson($share->json('share_url'))->assertOk()->assertJsonPath('invoice.remaining_balance', '75.00');

    $reminder = $this->postJson(route('payment-reminders.store'), ['invoice_id' => $invoice->id, 'message' => 'Please review your invoice.'])->assertCreated();
    $this->putJson(route('payment-reminders.update', $reminder->json('reminder.id')), ['message' => 'Updated reminder.'])->assertOk()->assertJsonPath('reminder.message', 'Updated reminder.');
    $this->getJson(route('payment-reminders.index'))->assertOk()->assertJsonCount(1, 'reminders');
    $this->assertDatabaseHas('payment_reminders', ['id' => $reminder->json('reminder.id'), 'status' => 'draft', 'sent_at' => null]);

    $product = $this->postJson(route('products.store'), ['name' => 'Stock item', 'quantity' => 5, 'low_stock_threshold' => 2])->assertCreated();
    $this->putJson(route('products.update', $product->json('product.id')), ['name' => 'Updated stock item'])->assertOk();
    $this->postJson(route('products.adjustments.store', $product->json('product.id')), ['quantity_change' => -2, 'notes' => 'Adjustment'])->assertCreated()->assertJsonPath('product.quantity', 3);
    $this->post(route('logout'))->assertRedirect(route('home'));
    $this->get(route('dashboard'))->assertRedirect(route('login'));
    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])->assertRedirect(route('dashboard', absolute: false));
    $this->getJson(route('products.index'))->assertOk()->assertJsonPath('products.0.name', 'Updated stock item')->assertJsonPath('products.0.quantity', 3);
    $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'status' => 'approved']);
});

test('customer details updates and deletion are isolated from other accounts', function () {
    $owner = User::factory()->create();
    $customer = $owner->customers()->create(['name' => 'Private customer', 'email' => 'private@example.test']);
    $this->actingAs(User::factory()->create());

    $this->get(route('customers.show', $customer))->assertNotFound();
    $this->put(route('customers.update', $customer), ['name' => 'Changed'])->assertNotFound();
    $this->delete(route('customers.destroy', $customer))->assertNotFound();
    $this->assertDatabaseHas('customers', ['id' => $customer->id, 'name' => 'Private customer']);
});

test('customers with invoices cannot be deleted and editable customers can be deleted', function () {
    $user = User::factory()->create();
    $customer = $user->customers()->create(['name' => 'Protected customer']);
    $user->invoices()->create(['customer_id' => $customer->id, 'invoice_number' => 'PROTECTED', 'currency' => 'USD', 'issue_date' => '2026-11-10', 'subtotal' => '10', 'total' => '10', 'status' => 'draft']);
    $emptyCustomer = $user->customers()->create(['name' => 'No invoices']);

    $this->actingAs($user)->delete(route('customers.destroy', $customer))->assertSessionHasErrors('customer');
    $this->assertModelExists($customer);
    $this->delete(route('customers.destroy', $emptyCustomer))->assertRedirect(route('customers.index'));
    $this->assertModelMissing($emptyCustomer);
});
