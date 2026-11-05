<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CustomerController extends Controller
{
    // Display the customers page
    public function index(Request $request)
    {
        return Inertia::render('Customers/Index', [
            'customers' => $request->user()
                ->customers()
                ->latest()
                ->get()
        ]);
    }

    // Save a new customer
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'phone' => 'nullable|string|max:30',
            'address' => 'nullable|string',
        ]);

        $request->user()->customers()->create($validated);

        return redirect()
            ->route('customers.index')
            ->with('success', 'Customer added successfully');
    }

    // Display one customer's information
    public function show(Request $request, Customer $customer)
    {
        $customer = $request->user()
            ->customers()
            ->findOrFail($customer->id);

        return Inertia::render('Customers/Show', [
            'customer' => $customer
        ]);
    }

    // Update customer information
    public function update(Request $request, Customer $customer)
    {
        $customer = $request->user()
            ->customers()
            ->findOrFail($customer->id);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'email' => 'sometimes|required|email|max:255',
            'phone' => 'nullable|string|max:30',
            'address' => 'nullable|string',
        ]);

        $customer->update($validated);

        return redirect()
            ->route('customers.index')
            ->with('success', 'Customer updated successfully');
    }

    // Delete a customer
    public function destroy(Request $request, Customer $customer)
    {
        $customer = $request->user()
            ->customers()
            ->findOrFail($customer->id);

        if ($customer->invoices()->exists()) {
            return back()->withErrors([
                'customer' => 'Cannot delete a customer who has invoices.'
            ]);
        }

        $customer->delete();

        return redirect()
            ->route('customers.index')
            ->with('success', 'Customer deleted successfully');
    }
}
