<?php

namespace App\Http\Controllers;

use App\Concerns\CustomerValidationRules;
use App\Models\Customer;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CustomerController extends Controller
{
    use CustomerValidationRules;

    // Display the customers page
    public function index(Request $request)
    {
        return Inertia::render('Customers/Index', [
            'customers' => $request->user()
                ->customers()
                ->latest()
                ->get(),
        ]);
    }

    // Save a new customer
    public function store(Request $request)
    {
        $validated = $request->validate($this->customerRules());

        $request->user()->customers()->create($validated);

        return redirect()
            ->route('customers.index')
            ->with('success', __('Customer added successfully'));
    }

    // Display one customer's information
    public function show(Request $request, Customer $customer)
    {
        $customer = $request->user()
            ->customers()
            ->findOrFail($customer->id);

        return Inertia::render('Customers/Show', [
            'customer' => $customer,
        ]);
    }

    // Update customer information
    public function update(Request $request, Customer $customer)
    {
        $customer = $request->user()
            ->customers()
            ->findOrFail($customer->id);

        $validated = $request->validate($this->customerRules($customer));

        $customer->update($validated);

        return redirect()
            ->route('customers.index')
            ->with('success', __('Customer updated successfully'));
    }

    // Delete a customer
    public function destroy(Request $request, Customer $customer)
    {
        $customer = $request->user()
            ->customers()
            ->findOrFail($customer->id);

        if ($customer->invoices()->exists()) {
            return back()->withErrors([
                'customer' => __('Cannot delete a customer who has invoices.'),
            ]);
        }

        $customer->delete();

        return redirect()
            ->route('customers.index')
            ->with('success', __('Customer deleted successfully'));
    }
}
