<?php

namespace App\Actions\AI;

use App\Actions\Inventory\StockAdjuster;
use App\Actions\Invoices\InvoiceBalance;
use App\Currency;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentReminder;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AccountOperations
{
    /**
     * @return array<string, mixed>
     */
    public function read(User $user, string $query): array
    {
        return match ($query) {
            'customers' => $this->customers($user),
            'products' => $this->products($user, false),
            'low_stock' => $this->products($user, true),
            'outstanding_invoices' => $this->outstandingInvoices($user),
            'revenue' => $this->revenue($user),
            'reminders' => $this->reminders($user),
            'summary' => $this->summary($user),
            default => throw ValidationException::withMessages([
                'query' => 'This account query is not supported.',
            ]),
        };
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{payload: array<string, mixed>, preview: array<string, mixed>}
     */
    public function prepare(
        User $user,
        string $operation,
        array $data,
        InvoiceBalance $balance,
    ): array {
        return match ($operation) {
            'customer.create' => $this->prepareCustomer($data),
            'product.create' => $this->prepareProduct($user, $data),
            'inventory.adjust' => $this->prepareInventoryAdjustment($user, $data),
            'reminder.create' => $this->prepareReminder($user, $data, $balance),
            default => throw ValidationException::withMessages([
                'action' => 'This account action is not supported.',
            ]),
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function execute(
        User $user,
        string $operation,
        array $payload,
        InvoiceBalance $balance,
        StockAdjuster $stockAdjuster,
    ): array {
        return match ($operation) {
            'customer.create' => $this->createCustomer($user, $payload),
            'product.create' => $this->createProduct($user, $payload, $stockAdjuster),
            'inventory.adjust' => $this->adjustInventory($user, $payload, $stockAdjuster),
            'reminder.create' => $this->createReminder($user, $payload, $balance),
            default => throw ValidationException::withMessages([
                'action' => 'This account action is no longer supported.',
            ]),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function customers(User $user): array
    {
        $customers = $user->customers()
            ->orderBy('name')
            ->orderBy('id')
            ->limit(50)
            ->get(['id', 'name', 'email', 'phone']);

        return [
            'query' => 'customers',
            'count' => $customers->count(),
            'customers' => $customers->map(fn (object $customer): array => [
                'id' => $customer->id,
                'name' => $customer->name,
                'email' => $customer->email,
                'phone' => $customer->phone,
            ])->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function products(User $user, bool $lowStockOnly): array
    {
        $query = $user->products()
            ->when($lowStockOnly, fn ($builder) => $builder->whereColumn('quantity', '<=', 'low_stock_threshold'))
            ->orderBy('name')
            ->orderBy('id');
        $count = (clone $query)->count();
        $products = $query->limit(50)->get([
            'id',
            'name',
            'sku',
            'quantity',
            'low_stock_threshold',
        ]);
        $queryName = $lowStockOnly ? 'low_stock' : 'products';

        return [
            'query' => $queryName,
            'count' => $count,
            'products' => $products->map(fn (Product $product): array => [
                'id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'quantity' => $product->quantity,
                'low_stock_threshold' => $product->low_stock_threshold,
                'is_low_stock' => $product->quantity <= $product->low_stock_threshold,
            ])->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function outstandingInvoices(User $user): array
    {
        $invoices = $user->invoices()
            ->where('status', 'approved')
            ->with([
                'customer:id,user_id,name',
                'payments' => fn (HasMany $query) => $query->where('status', 'completed'),
            ])
            ->orderBy('id')
            ->limit(50)
            ->get();
        $outstanding = [];

        foreach ($invoices as $invoice) {
            $paidMinor = 0;

            foreach ($invoice->payments as $payment) {
                if ($payment->currency !== $invoice->currency) {
                    continue;
                }

                $paymentMinor = Currency::minor((string) $payment->amount, $invoice->currency);

                if ($paymentMinor > PHP_INT_MAX - $paidMinor) {
                    throw new \OverflowException('The payment total exceeds the supported range.');
                }

                $paidMinor += $paymentMinor;
            }

            $remainingMinor = max(0, Currency::minor((string) $invoice->total, $invoice->currency) - $paidMinor);

            if ($remainingMinor === 0) {
                continue;
            }

            $outstanding[] = [
                'id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'customer_name' => $invoice->customer?->name,
                'currency' => $invoice->currency,
                'total' => Currency::format(
                    Currency::minor((string) $invoice->total, $invoice->currency),
                    $invoice->currency
                ),
                'paid' => Currency::format($paidMinor, $invoice->currency),
                'remaining_balance' => Currency::format($remainingMinor, $invoice->currency),
                'due_date' => $invoice->due_date?->toDateString(),
            ];
        }

        return [
            'query' => 'outstanding_invoices',
            'count' => count($outstanding),
            'invoices' => $outstanding,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function revenue(User $user): array
    {
        $totals = Payment::query()
            ->where('status', 'completed')
            ->whereHas('invoice', fn (Builder $query) => $query->where('user_id', $user->id))
            ->select('currency')
            ->selectRaw('SUM(amount) as total')
            ->groupBy('currency')
            ->orderBy('currency')
            ->get();

        return [
            'query' => 'revenue',
            'totals_by_currency' => $totals->map(fn (object $total): array => [
                'currency' => $total->currency,
                'total' => Currency::format(
                    Currency::minor((string) $total->total, $total->currency),
                    $total->currency
                ),
            ])->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function reminders(User $user): array
    {
        $reminders = PaymentReminder::query()
            ->whereHas('invoice', fn (Builder $query) => $query->where('user_id', $user->id))
            ->with('invoice:id,user_id,invoice_number')
            ->orderByDesc('id')
            ->limit(50)
            ->get(['id', 'invoice_id', 'status', 'delivery_channel', 'sent_at', 'prepared_at']);

        return [
            'query' => 'reminders',
            'count' => $reminders->count(),
            'reminders' => $reminders->map(fn (PaymentReminder $reminder): array => [
                'id' => $reminder->id,
                'invoice_number' => $reminder->invoice->invoice_number,
                'status' => $reminder->status,
                'delivery_channel' => $reminder->delivery_channel,
                'sent_at' => $reminder->sent_at?->toISOString(),
                'prepared_at' => $reminder->prepared_at?->toISOString(),
            ])->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(User $user): array
    {
        return [
            'query' => 'summary',
            'customers' => $user->customers()->count(),
            'products' => $user->products()->count(),
            'low_stock_products' => $user->products()
                ->whereColumn('quantity', '<=', 'low_stock_threshold')
                ->count(),
            'approved_invoices' => $user->invoices()->where('status', 'approved')->count(),
            'reminders' => PaymentReminder::query()
                ->whereHas('invoice', fn (Builder $builder) => $builder->where('user_id', $user->id))
                ->count(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{payload: array<string, mixed>, preview: array<string, mixed>}
     */
    private function prepareCustomer(array $data): array
    {
        $validated = Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:5000'],
        ])->validate();
        $validated['name'] = trim($validated['name']);

        return [
            'payload' => $validated,
            'preview' => ['customer' => $validated],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{payload: array<string, mixed>, preview: array<string, mixed>}
     */
    private function prepareProduct(User $user, array $data): array
    {
        $validated = $this->validateProduct($user, $data);
        $validated['name'] = trim($validated['name']);
        $validated['quantity'] = (int) ($validated['quantity'] ?? 0);
        $validated['low_stock_threshold'] = (int) ($validated['low_stock_threshold'] ?? 0);

        return [
            'payload' => $validated,
            'preview' => ['product' => $validated],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{payload: array<string, mixed>, preview: array<string, mixed>}
     */
    private function prepareInventoryAdjustment(User $user, array $data): array
    {
        $validated = Validator::make($data, [
            'product_name' => ['required', 'string', 'max:255'],
            'quantity_change' => ['required', 'integer', 'between:-2147483647,2147483647', 'not_in:0'],
            'notes' => ['nullable', 'string', 'max:500'],
        ])->validate();
        $products = $user->products()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($validated['product_name']))])
            ->limit(2)
            ->get(['id', 'name', 'quantity', 'low_stock_threshold']);

        if ($products->count() !== 1) {
            throw ValidationException::withMessages([
                'product_name' => $products->isEmpty()
                    ? 'No product with that name exists in this account.'
                    : 'More than one product has that name; use a unique product name.',
            ]);
        }

        $product = $products->first();
        $quantityBefore = $product->quantity;
        $quantityChange = (int) $validated['quantity_change'];
        $quantityAfter = $quantityBefore + $quantityChange;

        if ($quantityAfter < 0 || $quantityAfter > 2_147_483_647) {
            throw ValidationException::withMessages([
                'quantity_change' => 'The adjustment would produce an unsupported stock quantity.',
            ]);
        }

        return [
            'payload' => [
                'product_id' => $product->id,
                'quantity_change' => $quantityChange,
                'notes' => $validated['notes'] ?? null,
            ],
            'preview' => [
                'product_name' => $product->name,
                'quantity_before' => $quantityBefore,
                'quantity_change' => $quantityChange,
                'quantity_after' => $quantityAfter,
                'notes' => $validated['notes'] ?? null,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{payload: array<string, mixed>, preview: array<string, mixed>}
     */
    private function prepareReminder(User $user, array $data, InvoiceBalance $balance): array
    {
        $validated = Validator::make($data, [
            'invoice_number' => ['required', 'string', 'max:255'],
            'message' => ['required', 'string', 'max:10000'],
        ])->validate();
        $invoices = $user->invoices()
            ->with('customer')
            ->where('invoice_number', trim($validated['invoice_number']))
            ->where('status', 'approved')
            ->limit(2)
            ->get();

        if ($invoices->count() !== 1) {
            throw ValidationException::withMessages([
                'invoice_number' => $invoices->isEmpty()
                    ? 'No approved invoice with an outstanding balance was found in this account.'
                    : 'More than one invoice has that number; use a unique invoice number.',
            ]);
        }

        $invoice = $invoices->first();

        if ($invoice->customer?->user_id !== $user->id || $balance->remainingMinor($invoice) <= 0) {
            throw ValidationException::withMessages([
                'invoice_number' => 'The selected invoice does not have an outstanding balance.',
            ]);
        }

        return [
            'payload' => [
                'invoice_id' => $invoice->id,
                'message' => $validated['message'],
            ],
            'preview' => [
                'invoice_number' => $invoice->invoice_number,
                'customer_name' => $invoice->customer->name,
                'balance_due' => Currency::format(
                    $balance->remainingMinor($invoice),
                    $invoice->currency
                ),
                'currency' => $invoice->currency,
                'message' => $validated['message'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function createCustomer(User $user, array $data): array
    {
        $validated = Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:5000'],
        ])->validate();

        $customer = $user->customers()->create($validated);

        return [
            'customer' => [
                'id' => $customer->id,
                'name' => $customer->name,
                'email' => $customer->email,
                'phone' => $customer->phone,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function createProduct(User $user, array $data, StockAdjuster $stockAdjuster): array
    {
        $validated = $this->validateProduct($user, $data);
        $quantity = (int) ($validated['quantity'] ?? 0);
        $product = $user->products()->create([
            'name' => trim($validated['name']),
            'sku' => $validated['sku'] ?? null,
            'description' => $validated['description'] ?? null,
            'low_stock_threshold' => (int) ($validated['low_stock_threshold'] ?? 0),
        ]);

        if ($quantity > 0) {
            $stockAdjuster->handle($user, $product, $quantity, 'Initial stock');
            $product->refresh();
        }

        return [
            'product' => [
                'id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'quantity' => $product->quantity,
                'low_stock_threshold' => $product->low_stock_threshold,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function adjustInventory(User $user, array $data, StockAdjuster $stockAdjuster): array
    {
        $validated = Validator::make($data, [
            'product_id' => ['required', 'integer'],
            'quantity_change' => ['required', 'integer', 'between:-2147483647,2147483647', 'not_in:0'],
            'notes' => ['nullable', 'string', 'max:500'],
        ])->validate();
        $product = $user->products()->findOrFail($validated['product_id']);
        $adjustment = $stockAdjuster->handle(
            $user,
            $product,
            (int) $validated['quantity_change'],
            $validated['notes'] ?? null,
        );

        return [
            'adjustment' => [
                'id' => $adjustment->id,
                'product_id' => $adjustment->product_id,
                'quantity_before' => $adjustment->quantity_before,
                'quantity_change' => $adjustment->quantity_change,
                'quantity_after' => $adjustment->quantity_after,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function createReminder(User $user, array $data, InvoiceBalance $balance): array
    {
        $validated = Validator::make($data, [
            'invoice_id' => ['required', 'integer'],
            'message' => ['required', 'string', 'max:10000'],
        ])->validate();
        $invoice = $user->invoices()
            ->with('customer')
            ->whereKey($validated['invoice_id'])
            ->where('status', 'approved')
            ->lockForUpdate()
            ->firstOrFail();
        $remainingMinor = $balance->remainingMinor($invoice);

        if ($invoice->customer?->user_id !== $user->id || $remainingMinor <= 0) {
            throw ValidationException::withMessages([
                'invoice_id' => 'A reminder can only be created for an approved invoice with an outstanding balance.',
            ]);
        }

        $reminder = $invoice->reminders()->create([
            'message' => $validated['message'],
            'status' => 'draft',
        ]);

        return [
            'reminder' => [
                'id' => $reminder->id,
                'invoice_id' => $invoice->id,
                'status' => $reminder->status,
                'message' => $reminder->message,
            ],
            'balance_due' => Currency::format($remainingMinor, $invoice->currency),
            'currency' => $invoice->currency,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validateProduct(User $user, array $data): array
    {
        return Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'sku' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('products', 'sku')->where('user_id', $user->id),
            ],
            'description' => ['nullable', 'string', 'max:5000'],
            'quantity' => ['sometimes', 'integer', 'min:0', 'max:2147483647'],
            'low_stock_threshold' => ['sometimes', 'integer', 'min:0', 'max:2147483647'],
        ])->validate();
    }
}
