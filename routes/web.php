<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\InvoiceController;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {

    Route::inertia('dashboard', 'Dashboard')
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
});

require __DIR__ . '/settings.php';