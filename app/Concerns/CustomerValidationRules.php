<?php

namespace App\Concerns;

use App\Models\Customer;

trait CustomerValidationRules
{
    /**
     * @return array<string, list<string>>
     */
    protected function customerRules(?Customer $customer = null): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['required', 'string', 'max:30'],
            'address' => ['nullable', 'string'],
        ];

        if ($customer !== null) {
            foreach (['name', 'phone'] as $field) {
                if (filled($customer->{$field})) {
                    array_unshift($rules[$field], 'sometimes');
                }
            }
        }

        return $rules;
    }
}
