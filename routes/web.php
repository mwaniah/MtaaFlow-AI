<?php

use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');

    Route::inertia('invoices', 'Invoices/Index')->name('invoices.index');
    Route::inertia('invoices/create', 'Invoices/Create')->name('invoices.create');

    Route::inertia('customers', 'Customers/Index')->name('customers.index');
    Route::inertia('payments', 'Payments/Index')->name('payments.index');
    Route::inertia('inventory', 'Inventory/Index')->name('inventory.index');
    Route::inertia('ask', 'AskMtaaFlow/Index')->name('ask.index');
    Route::inertia('insights', 'Insights/Index')->name('insights.index');
});

require __DIR__.'/settings.php';
