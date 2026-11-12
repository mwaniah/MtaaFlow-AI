<?php

namespace App\Actions\AI;

use App\Actions\Inventory\StockAdjuster;
use App\Actions\Invoices\InvoiceBalance;
use App\Actions\Invoices\InvoiceWriter;
use App\Concerns\CustomerValidationRules;
use App\Currency;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
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
    use CustomerValidationRules;

    public function __construct(private InvoiceWriter $invoices) {}

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
                'query' => __('This account query is not supported.'),
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
        if ($operation === 'product.create') {
            $this->rejectProductPrices($data);
        } elseif ($operation === 'product.update' && is_array($data['changes'] ?? null)) {
            $this->rejectProductPrices($data['changes']);
        }

        $this->ensureSupportedFields($data, match ($operation) {
            'customer.create' => ['name', 'email', 'phone', 'address'],
            'customer.update', 'customer.delete' => ['customer_id', 'customer_name', 'changes'],
            'product.create' => ['name', 'sku', 'description', 'quantity', 'low_stock_threshold'],
            'product.update' => ['product_id', 'product_name', 'changes'],
            'inventory.adjust' => ['product_id', 'product_name', 'quantity_change', 'notes'],
            'invoice.update', 'invoice.approve' => ['invoice_id', 'invoice_number', 'changes'],
            'reminder.create' => ['invoice_number', 'message'],
            default => [],
        });

        return match ($operation) {
            'customer.create' => $this->prepareCustomer($data),
            'customer.update', 'customer.delete', 'product.update' => $this->prepareRecordChange($user, $operation, $data),
            'invoice.update', 'invoice.approve' => $this->prepareInvoiceChange($user, $operation, $data),
            'product.create' => $this->prepareProduct($user, $data),
            'inventory.adjust' => $this->prepareInventoryAdjustment($user, $data),
            'reminder.create' => $this->prepareReminder($user, $data, $balance),
            default => throw ValidationException::withMessages([
                'action' => __('This account action is not supported.'),
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
            'customer.update', 'customer.delete', 'product.update' => $this->changeRecord($user, $operation, $payload),
            'invoice.update', 'invoice.approve' => $this->changeInvoice($user, $operation, $payload),
            'product.create' => $this->createProduct($user, $payload, $stockAdjuster),
            'inventory.adjust' => $this->adjustInventory($user, $payload, $stockAdjuster),
            'reminder.create' => $this->createReminder($user, $payload, $balance),
            default => throw ValidationException::withMessages([
                'action' => __('This account action is no longer supported.'),
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
            ->whereHas('invoice', fn (Builder $query) => $query->where('user_id', $user->id)->whereColumn('invoices.currency', 'payments.currency'))
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
            'total_invoices' => $user->invoices()->count(),
            'paid_invoices' => $user->invoices()->where('status', 'paid')->count(),
            'totals_by_currency' => $this->revenue($user)['totals_by_currency'],
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
    private function prepareRecordChange(User $user, string $operation, array $data): array
    {
        $kind = str_starts_with($operation, 'customer.') ? 'customer' : 'product';
        $validated = Validator::make($data, [
            $kind.'_id' => ['nullable', 'integer', 'min:1'],
            $kind.'_name' => ['required_without:'.$kind.'_id', 'nullable', 'string', 'max:255'],
            'changes' => [$operation === 'customer.delete' ? 'nullable' : 'required', 'array'],
        ])->validate();
        $record = $this->resolveRecord($user, $kind, $validated);
        if ($operation === 'customer.delete' && $record->invoices()->exists()) {
            throw ValidationException::withMessages(['customer' => __('Cannot delete a customer who has invoices.')]);
        }

        $changes = $operation === 'customer.delete'
            ? []
            : $this->validateRecordChanges($user, $record, $validated['changes']);

        return ['payload' => [$kind.'_id' => $record->id, 'changes' => $changes], 'preview' => ['operation' => $operation, 'name' => $record->name, 'changes' => $changes]];
    }

    /**
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    private function validateRecordChanges(User $user, Customer|Product $record, array $changes): array
    {
        if ($record instanceof Product) {
            $this->rejectProductPrices($changes);
        }

        $rules = $record instanceof Customer ? $this->customerRules($record) : [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'sku' => ['sometimes', 'nullable', 'string', 'max:255', Rule::unique('products', 'sku')->where('user_id', $user->id)->ignore($record->id)],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'low_stock_threshold' => ['sometimes', 'required', 'integer', 'min:0', 'max:2147483647'],
        ];

        $this->ensureSupportedFields($changes, array_keys($rules));

        return Validator::make($changes, $rules)->validate();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function changeRecord(User $user, string $operation, array $payload): array
    {
        $kind = str_starts_with($operation, 'customer.') ? 'customer' : 'product';
        $query = $kind === 'customer' ? $user->customers() : $user->products();
        $record = $query->whereKey($payload[$kind.'_id'])->lockForUpdate()->firstOrFail();

        if ($operation === 'customer.delete') {
            if ($record->invoices()->exists()) {
                throw ValidationException::withMessages(['customer' => __('Cannot delete a customer who has invoices.')]);
            }
            $record->delete();

            return ['deleted' => true, 'name' => $record->name];
        }

        $record->update($this->validateRecordChanges($user, $record, $payload['changes']));

        return [$kind => ['id' => $record->id, 'name' => $record->name]];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveRecord(User $user, string $kind, array $data): Customer|Product|Invoice
    {
        $nameField = $kind === 'invoice' ? 'invoice_number' : $kind.'_name';
        $column = $kind === 'invoice' ? 'invoice_number' : 'name';
        $query = match ($kind) {
            'customer' => $user->customers(),
            'product' => $user->products(),
            'invoice' => $user->invoices()->with('customer'),
        };

        if (isset($data[$kind.'_id'])) {
            $query->whereKey($data[$kind.'_id']);
        }
        if (isset($data[$nameField])) {
            $query->whereRaw('LOWER('.$column.') = ?', [mb_strtolower(trim($data[$nameField]))]);
        }

        $records = $query->orderBy('id')->limit(10)->get();

        if ($records->count() !== 1) {
            throw ValidationException::withMessages([
                isset($data[$kind.'_id']) ? $kind.'_id' : $nameField => $records->isEmpty()
                    ? ($kind === 'product' && ! isset($data['product_id']) ? __('No product with that name exists in this account.') : __('No matching record exists in your account.'))
                    : __('Choose a record by ID: ').$records->map(fn ($record): string => $record->id.': '.$record->{$column})->implode('; '),
            ]);
        }

        $record = $records->first();

        if ($record instanceof Invoice && $record->customer?->user_id !== $user->id) {
            throw ValidationException::withMessages(['invoice_id' => __('No matching record exists in your account.')]);
        }

        return $record;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{payload: array<string, mixed>, preview: array<string, mixed>}
     */
    private function prepareInvoiceChange(User $user, string $operation, array $data): array
    {
        $validated = Validator::make($data, [
            'invoice_id' => ['nullable', 'integer', 'min:1'],
            'invoice_number' => ['required_without:invoice_id', 'nullable', 'string', 'max:255'],
            'changes' => [$operation === 'invoice.approve' ? 'prohibited' : 'required', 'array', 'min:1'],
        ])->validate();
        $invoice = $this->resolveRecord($user, 'invoice', $validated)->load('items');
        $this->ensureDraftInvoice($invoice);
        $payload = ['invoice_id' => $invoice->id, 'snapshot' => $this->invoiceSnapshot($invoice)];
        $draft = $this->invoiceDraft($invoice);

        if ($operation === 'invoice.approve') {
            if ($invoice->items->isEmpty()) {
                throw ValidationException::withMessages(['invoice' => __('An invoice must have at least one item.')]);
            }
        } else {
            $changes = $validated['changes'];
            $this->ensureSupportedFields($changes, ['customer_id', 'customer_name', 'currency', 'issue_date', 'due_date', 'items', 'append_items']);

            if (array_key_exists('items', $changes) && array_key_exists('append_items', $changes)) {
                throw ValidationException::withMessages(['items' => __('Provide replacement items or appended items, not both.')]);
            }

            if (isset($changes['customer_id']) || isset($changes['customer_name'])) {
                $selectors = Validator::make($changes, [
                    'customer_id' => ['nullable', 'integer', 'min:1'],
                    'customer_name' => ['nullable', 'string', 'max:255'],
                ])->validate();
                $changes['customer_id'] = $this->resolveRecord($user, 'customer', $selectors)->id;
                unset($changes['customer_name']);
            }

            if (array_key_exists('append_items', $changes)) {
                Validator::make($changes, ['append_items' => ['required', 'array', 'min:1', 'max:100']])->validate();
                $changes['items'] = [...$draft['items'], ...$changes['append_items']];
                unset($changes['append_items']);
            }

            $draft = $this->invoices->validate($user, array_replace($draft, $changes));
            $payload['draft'] = $draft;
        }

        return [
            'payload' => $payload,
            'preview' => [
                'operation' => $operation,
                'invoice' => [
                    'id' => $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                    ...$draft,
                    'total' => Currency::format($this->invoices->totalMinor($draft), $draft['currency']),
                    'status' => $operation === 'invoice.approve' ? 'approved' : 'draft',
                    'url' => route('invoices.show', $invoice),
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function changeInvoice(User $user, string $operation, array $payload): array
    {
        $validated = Validator::make($payload, [
            'invoice_id' => ['required', 'integer', 'min:1'],
            'snapshot' => ['required', 'string', 'size:64'],
            'draft' => [$operation === 'invoice.update' ? 'required' : 'prohibited', 'array'],
        ])->validate();
        $invoice = $user->invoices()->with(['items', 'customer'])->whereKey($validated['invoice_id'])->lockForUpdate()->firstOrFail();
        $this->ensureDraftInvoice($invoice);

        if ($invoice->customer?->user_id !== $user->id) {
            abort(404);
        }
        if (! hash_equals($validated['snapshot'], $this->invoiceSnapshot($invoice))) {
            throw ValidationException::withMessages(['invoice' => __('The invoice changed after this preview. Please request a new preview.')]);
        }

        if ($operation === 'invoice.approve') {
            $this->invoices->approve($invoice);
        } else {
            $this->invoices->updateDraft($invoice, $this->invoices->validate($user, $validated['draft']));
        }

        $invoice->refresh();

        return ['invoice' => [
            'id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'status' => $invoice->status,
            'currency' => $invoice->currency,
            'total' => Currency::format(Currency::minor((string) $invoice->total, $invoice->currency), $invoice->currency),
            'url' => route('invoices.show', $invoice),
        ]];
    }

    private function ensureDraftInvoice(Invoice $invoice): void
    {
        if ($invoice->status !== 'draft') {
            throw ValidationException::withMessages(['invoice' => __('Only draft invoices can be modified.')]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function invoiceDraft(Invoice $invoice): array
    {
        return [
            'customer_id' => $invoice->customer_id,
            'currency' => $invoice->currency,
            'issue_date' => $invoice->issue_date?->toDateString(),
            'due_date' => $invoice->due_date?->toDateString(),
            'items' => $invoice->items->sortBy('id')->map(fn (InvoiceItem $item): array => [
                'description' => $item->description,
                'quantity' => (int) $item->quantity,
                'unit_price' => Currency::format(Currency::minor((string) $item->unit_price, $invoice->currency), $invoice->currency),
            ])->values()->all(),
        ];
    }

    private function invoiceSnapshot(Invoice $invoice): string
    {
        return hash('sha256', json_encode([
            ...$this->invoiceDraft($invoice),
            'status' => $invoice->status,
            'total' => (string) $invoice->total,
            'items_ids' => $invoice->items->sortBy('id')->pluck('id')->values()->all(),
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function rejectProductPrices(array $data): void
    {
        if (array_intersect(['price', 'selling_price', 'unit_price', 'currency'], array_keys($data)) !== []) {
            throw ValidationException::withMessages(['price' => __('Inventory products do not store selling prices or currencies. Use invoice item prices instead.')]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $supported
     */
    private function ensureSupportedFields(array $data, array $supported): void
    {
        if (array_diff(array_keys($data), $supported) !== []) {
            throw ValidationException::withMessages(['changes' => __('This account action is not supported.')]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{payload: array<string, mixed>, preview: array<string, mixed>}
     */
    private function prepareCustomer(array $data): array
    {
        $validated = Validator::make($data, $this->customerRules())->validate();
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
            'product_id' => ['nullable', 'integer', 'min:1'],
            'product_name' => ['required_without:product_id', 'nullable', 'string', 'max:255'],
            'quantity_change' => ['required', 'integer', 'between:-2147483647,2147483647', 'not_in:0'],
            'notes' => ['nullable', 'string', 'max:500'],
        ])->validate();
        $product = $this->resolveRecord($user, 'product', $validated);
        $quantityBefore = $product->quantity;
        $quantityChange = (int) $validated['quantity_change'];
        $quantityAfter = $quantityBefore + $quantityChange;

        if ($quantityAfter < 0 || $quantityAfter > 2_147_483_647) {
            throw ValidationException::withMessages([
                'quantity_change' => __('The adjustment would produce an unsupported stock quantity.'),
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
                    ? __('No approved invoice with an outstanding balance was found in this account.')
                    : __('More than one invoice has that number; use a unique invoice number.'),
            ]);
        }

        $invoice = $invoices->first();

        if ($invoice->customer?->user_id !== $user->id || $balance->remainingMinor($invoice) <= 0) {
            throw ValidationException::withMessages([
                'invoice_number' => __('The selected invoice does not have an outstanding balance.'),
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
        $validated = Validator::make($data, $this->customerRules())->validate();

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
                'invoice_id' => __('A reminder can only be created for an approved invoice with an outstanding balance.'),
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
        $this->rejectProductPrices($data);

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
