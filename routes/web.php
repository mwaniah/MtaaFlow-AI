<?php

use App\Http\Controllers\AIController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PaymentReminderController;
use App\Http\Controllers\ReportsController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {

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

    Route::get('payments', [PaymentController::class, 'index'])
        ->name('payments.index');

    Route::get('payments/{payment}', [PaymentController::class, 'show'])
        ->name('payments.show');

    Route::post('ai/generate-invoice', [AIController::class, 'generateInvoice'])
        ->middleware('throttle:20,1')
        ->name('ai.invoice.generate');

    Route::post('ai/ask', [AIController::class, 'ask'])
        ->middleware('throttle:20,1')
        ->name('ai.ask');

    Route::get('payment-reminders', [PaymentReminderController::class, 'index'])
        ->name('payment-reminders.index');

    Route::post('payment-reminders', [PaymentReminderController::class, 'store'])
        ->name('payment-reminders.store');

    Route::put('payment-reminders/{paymentReminder}', [PaymentReminderController::class, 'update'])
        ->name('payment-reminders.update');

    Route::patch('payment-reminders/{paymentReminder}', [PaymentReminderController::class, 'update']);

    Route::delete('payment-reminders/{paymentReminder}', [PaymentReminderController::class, 'destroy'])
        ->name('payment-reminders.destroy');

    Route::get('reports', [ReportsController::class, 'index'])
        ->name('reports.index');
});

require __DIR__.'/settings.php';
