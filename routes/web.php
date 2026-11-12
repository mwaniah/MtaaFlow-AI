<?php

use App\Http\Controllers\AIController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\InventoryAdjustmentController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\InvoiceShareController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PaymentReminderController;
use App\Http\Controllers\PaymentReminderDeliveryController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ReportsController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::get('shared/invoices/{token}', [InvoiceShareController::class, 'show'])
    ->middleware('throttle:60,1')
    ->where('token', '[A-Za-z0-9]{64}')
    ->name('invoice-shares.public');

Route::post('shared/invoices/{token}/paypal/orders', [PaymentController::class, 'createSharedPaypalOrder'])
    ->middleware('throttle:10,1')
    ->where('token', '[A-Za-z0-9]{64}')
    ->name('invoice-shares.paypal.orders');

Route::post('shared/invoices/{token}/paypal/orders/{orderId}/capture', [PaymentController::class, 'captureSharedPaypalOrder'])
    ->middleware('throttle:10,1')
    ->where('token', '[A-Za-z0-9]{64}')
    ->where('orderId', '[A-Za-z0-9-]+')
    ->name('invoice-shares.paypal.capture');

Route::middleware(['auth'])->group(function () {

    Route::get('dashboard', [DashboardController::class, 'index'])
        ->name('dashboard');

    // Customer page handled by CustomerController
    Route::get('customers', [CustomerController::class, 'index'])
        ->name('customers.index');

    // Customer operations
    Route::post('customers', [CustomerController::class, 'store'])
        ->name('customers.store');

    Route::get('customers/{customer}', [CustomerController::class, 'show'])
        ->name('customers.show');

    Route::put('customers/{customer}', [CustomerController::class, 'update'])
        ->name('customers.update');

    Route::patch('customers/{customer}', [CustomerController::class, 'update']);

    Route::delete('customers/{customer}', [CustomerController::class, 'destroy'])
        ->name('customers.destroy');
    // Invoice pages
    Route::get('invoices', [InvoiceController::class, 'index'])
        ->name('invoices.index');

    Route::get('invoices/create', [InvoiceController::class, 'create'])
        ->name('invoices.create');

    Route::post('invoices', [InvoiceController::class, 'store'])
        ->name('invoices.store');

    Route::get('invoices/{id}', [InvoiceController::class, 'show'])
        ->name('invoices.show');

    Route::get('invoices/{id}/edit', [InvoiceController::class, 'edit'])
        ->name('invoices.edit');

    Route::put('invoices/{id}', [InvoiceController::class, 'update'])
        ->name('invoices.update');

    Route::patch('invoices/{id}', [InvoiceController::class, 'update']);

    Route::delete('invoices/{id}', [InvoiceController::class, 'destroy'])
        ->name('invoices.destroy');

    Route::post('invoices/{id}/approve', [InvoiceController::class, 'approve'])
        ->name('invoices.approve');

    Route::post('invoices/{invoice}/shares', [InvoiceShareController::class, 'store'])
        ->name('invoices.shares.store');

    Route::delete('invoices/{invoice}/shares/{invoiceShare}', [InvoiceShareController::class, 'destroy'])
        ->name('invoices.shares.destroy');

    Route::post('invoices/{invoice}/shares/{invoiceShare}/email', [InvoiceShareController::class, 'email'])
        ->middleware('throttle:10,1')
        ->name('invoices.shares.email');

    Route::get('payments', [PaymentController::class, 'index'])
        ->name('payments.index');

    Route::get('payments/{payment}', [PaymentController::class, 'show'])
        ->name('payments.show');

    Route::post('invoices/{invoice}/paypal/orders', [PaymentController::class, 'createPaypalOrder'])
        ->middleware('throttle:10,1')
        ->name('payments.paypal.orders');

    Route::post('paypal/orders/{orderId}/capture', [PaymentController::class, 'capturePaypalOrder'])
        ->middleware('throttle:10,1')
        ->where('orderId', '[A-Za-z0-9-]+')
        ->name('payments.paypal.capture');

    Route::post('ai/generate-invoice', [AIController::class, 'generateInvoice'])
        ->middleware('throttle:20,1')
        ->name('ai.invoice.generate');

    Route::post('ai/invoice/confirm', [AIController::class, 'confirmInvoice'])
        ->middleware('throttle:20,1')
        ->name('ai.invoice.confirm');

    Route::post('ai/actions/cancel', [AIController::class, 'cancelAction'])
        ->middleware('throttle:20,1')->name('ai.actions.cancel');

    Route::post('ai/actions/confirm', [AIController::class, 'confirmAction'])
        ->middleware('throttle:20,1')
        ->name('ai.actions.confirm');

    Route::post('ai/ask', [AIController::class, 'ask'])
        ->middleware('throttle:20,1')
        ->name('ai.ask');


    Route::get('ai/conversations', [
        \App\Actions\AI\AskConversation::class,
        'index'
    ])->name('ai.conversations.index');

    Route::get('ai/conversations/{id}', [
        \App\Actions\AI\AskConversation::class,
        'show'
    ])->whereUuid('id')->name('ai.conversations.show');

    Route::delete('ai/conversations/{id}', [
        \App\Actions\AI\AskConversation::class,
        'destroy'
    ])->whereUuid('id')->name('ai.conversations.destroy');


    Route::get('payment-reminders', [PaymentReminderController::class, 'index'])
        ->name('payment-reminders.index');

    Route::post('payment-reminders', [PaymentReminderController::class, 'store'])
        ->name('payment-reminders.store');

    Route::put('payment-reminders/{paymentReminder}', [PaymentReminderController::class, 'update'])
        ->name('payment-reminders.update');

    Route::patch('payment-reminders/{paymentReminder}', [PaymentReminderController::class, 'update']);

    Route::delete('payment-reminders/{paymentReminder}', [PaymentReminderController::class, 'destroy'])
        ->name('payment-reminders.destroy');

    Route::post('payment-reminders/{paymentReminder}/deliveries', [PaymentReminderDeliveryController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('payment-reminders.deliveries.store');

    Route::get('reports', [ReportsController::class, 'index'])
        ->name('reports.index');

    Route::apiResource('products', ProductController::class);

    Route::get('products/{product}/adjustments', [InventoryAdjustmentController::class, 'index'])
        ->name('products.adjustments.index');

    Route::post('products/{product}/adjustments', [InventoryAdjustmentController::class, 'store'])
        ->name('products.adjustments.store');
});

require __DIR__ . '/settings.php';

// Additional authenticated application pages.
Route::middleware(['auth'])->group(function () {
    Route::inertia('inventory', 'Inventory/Index')->name('inventory.index');
    Route::inertia('ask', 'AskMtaaFlow/Index')->name('ask.index');
    Route::get('insights', fn() => redirect()->route('reports.index'))->name('insights.index');
});
