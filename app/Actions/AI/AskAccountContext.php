<?php

namespace App\Actions\AI;

use App\Actions\Invoices\InvoiceBalance;
use App\Currency;
use App\Models\InvoiceItem;
use App\Models\InvoiceShare;
use App\Models\Payment;
use App\Models\PaymentReminder;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use OverflowException;

class AskAccountContext
{
    public function __construct(private InvoiceBalance $balance) {}

    /**
     * @param  array<string, mixed>  $options
     * @return array{answer: string, data: array<string, mixed>}
     */
    public function read(User $user, string $query, array $options = []): array
    {
        $validated = Validator::make(['query' => $query, 'options' => $options], [
            'query' => ['required', Rule::in(['customers', 'invoices', 'outstanding_invoices', 'payments', 'revenue', 'products', 'low_stock', 'reminders', 'summary'])],
            'options' => ['array:mode,since,until,currency,status,limit,search,customer_id,customer_name,product_id,product_name,invoice_id,invoice_number,overdue,include_sharing'],
            'options.mode' => ['sometimes', Rule::in(['count', 'list'])],
            'options.since' => ['sometimes', 'date_format:Y-m-d'],
            'options.until' => ['sometimes', 'date_format:Y-m-d', ...(isset($options['since']) ? ['after_or_equal:options.since'] : [])],
            'options.currency' => [
                'sometimes', 'string', Rule::in(array_keys(Currency::all())),
                Rule::prohibitedIf(! in_array($query, ['invoices', 'outstanding_invoices', 'payments', 'revenue', 'summary'], true)),
            ],
            'options.status' => ['sometimes', Rule::in(match ($query) {
                'invoices' => ['draft', 'approved', 'paid', 'cancelled'],
                'payments' => ['pending', 'processing', 'completed', 'failed', 'cancelled'],
                'reminders' => ['draft', 'sent', 'failed'],
                default => [],
            })],
            'options.limit' => ['sometimes', 'integer', 'between:1,20'],
            'options.search' => ['sometimes', 'string', 'min:1', 'max:255', Rule::prohibitedIf(! in_array($query, ['customers', 'products', 'low_stock'], true))],
            'options.customer_id' => ['sometimes', 'integer', 'min:1', Rule::prohibitedIf(! in_array($query, ['customers', 'invoices', 'outstanding_invoices', 'payments', 'revenue'], true))],
            'options.customer_name' => ['sometimes', 'string', 'min:1', 'max:255', Rule::prohibitedIf(! in_array($query, ['customers', 'invoices', 'outstanding_invoices', 'payments', 'revenue'], true))],
            'options.product_id' => ['sometimes', 'integer', 'min:1', Rule::prohibitedIf(! in_array($query, ['products', 'low_stock'], true))],
            'options.product_name' => ['sometimes', 'string', 'min:1', 'max:255', Rule::prohibitedIf(! in_array($query, ['products', 'low_stock'], true))],
            'options.invoice_id' => ['sometimes', 'integer', 'min:1', Rule::prohibitedIf(! in_array($query, ['invoices', 'outstanding_invoices', 'payments', 'revenue'], true))],
            'options.invoice_number' => ['sometimes', 'string', 'min:1', 'max:255', Rule::prohibitedIf(! in_array($query, ['invoices', 'outstanding_invoices', 'payments', 'revenue'], true))],
            'options.overdue' => ['sometimes', 'boolean', Rule::prohibitedIf(! in_array($query, ['invoices', 'outstanding_invoices'], true))],
            'options.include_sharing' => ['sometimes', 'boolean', Rule::prohibitedIf(! in_array($query, ['invoices', 'outstanding_invoices'], true))],
        ])->validate();
        $options = $this->resolveSelectors($user, $validated['options']);

        $result = match ($query) {
            'customers' => $this->customers($user, $options),
            'invoices', 'outstanding_invoices' => $this->invoices($user, $query === 'outstanding_invoices', $options),
            'payments' => $this->payments($user, $options),
            'revenue' => $this->revenue($user, $options),
            'products', 'low_stock' => $this->products($user, $query === 'low_stock', $options),
            'reminders' => $this->reminders($user, $options),
            'summary' => $this->summary($user, $options),
        };

        if (app()->getLocale() !== 'en' || $this->targeted($options) || ($options['overdue'] ?? false) || ($options['include_sharing'] ?? false)) {
            $result['answer'] = $this->localizedAnswer($result['data'], $options);
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $options
     */
    private function localizedAnswer(array $data, array $options): string
    {
        $query = $data['query'];
        $period = (isset($options['currency']) ? __('assistant.currency', ['currency' => $options['currency']]) : '')
            .(isset($options['since']) ? __('assistant.since', ['date' => $options['since']]) : '')
            .(isset($options['until']) ? __('assistant.until', ['date' => $options['until']]) : '');
        $earnings = fn (): string => ($data['totals_by_currency'] ?? []) === []
            ? __('assistant.no_earnings')
            : __('assistant.earnings', ['amounts' => implode('; ', array_map(fn (array $total): string => $total['currency'].' '.$total['total'], $data['totals_by_currency']))]);

        if ($query === 'revenue') {
            return $earnings().$period.'.';
        }

        if ($query === 'summary') {
            return __('assistant.summary').$period.":\n\n".$this->listing([
                __('assistant.customers', ['count' => $data['customers']]),
                __('assistant.invoices', ['count' => $data['total_invoices']]),
                __('assistant.paid', ['count' => $data['paid_invoices']]),
                __('assistant.outstanding_invoices', ['count' => $data['outstanding_invoices']]),
                __('assistant.products', ['count' => $data['products']]),
                __('assistant.low_stock', ['count' => $data['low_stock_products']]),
                __('assistant.reminders', ['count' => $data['reminders']]),
                $earnings(),
            ])."\n\n".__('assistant.financial_note');
        }

        $records = $data[match ($query) {
            'outstanding_invoices' => 'invoices', 'low_stock' => 'products', default => $query,
        }] ?? [];
        $heading = __('assistant.'.$query, ['count' => $data['count']]).$period.'.';
        $lines = array_map(function (array $record) use ($query): string {
            if ($query === 'customers') {
                $line = isset($record['id']) ? '#'.$record['id'].' — '.$record['name'] : $record['name'];

                foreach (['phone', 'email', 'address'] as $field) {
                    if (array_key_exists($field, $record)) {
                        $line .= "\n  ".__('assistant.field_'.$field).': '.($record[$field] === null || $record[$field] === '' ? __('assistant.not_set') : $this->text($record[$field]));
                    }
                }

                return $line;
            }
            if (in_array($query, ['products', 'low_stock'], true)) {
                $line = __('assistant.stock', ['name' => (isset($record['sku']) || array_key_exists('description', $record) ? '#'.$record['id'].' — ' : '').$record['name'], 'quantity' => $record['quantity'], 'threshold' => $record['low_stock_threshold']]);
                foreach (['sku', 'description'] as $field) {
                    if (array_key_exists($field, $record)) {
                        $line .= "\n  ".__('assistant.field_'.$field).': '.($record[$field] === null || $record[$field] === '' ? __('assistant.not_set') : $this->text($record[$field]));
                    }
                }

                return $line;
            }
            $line = $record['invoice_number'];
            if (isset($record['customer_name'])) {
                $line .= __('assistant.for_customer', ['name' => $record['customer_name']]);
            }
            $amount = $record['remaining_balance'] ?? $record['amount'] ?? $record['total'] ?? null;
            if ($amount !== null) {
                $line .= ' — '.$record['currency'].' '.$amount;
            }
            if (isset($record['status'])) {
                $line .= ' — '.__('assistant.status_'.$record['status']);
            }
            if (isset($record['due_date'])) {
                $line .= __('assistant.due', ['date' => $record['due_date']]);
            }
            if (isset($record['date']) || isset($record['sent_at'])) {
                $line .= __('assistant.on', ['date' => $record['date'] ?? $record['sent_at']]);
            }

            foreach ($record['items'] ?? [] as $item) {
                $line .= "\n  ".__('assistant.invoice_item', ['quantity' => $item['quantity'], 'description' => $item['description'], 'currency' => $record['currency'], 'unit_price' => $item['unit_price']]);
            }
            if (isset($record['url'])) {
                $line .= "\n  [".__('assistant.open_invoice').']('.$record['url'].')';
            }
            if (isset($record['shares_count'])) {
                $line .= "\n  ".__('assistant.sharing_info', ['count' => $record['shares_count']]);
            }

            return $line.'.';
        }, $records);

        return $heading.($lines === [] ? '' : "\n\n".$this->listing($lines))
            .(($options['overdue'] ?? false) ? "\n\n".__('assistant.overdue_only') : '')
            .(count($records) > 0 && count($records) < $data['count'] ? "\n\n".__('assistant.showing', ['shown' => count($records), 'total' => $data['count']]) : '');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{answer: string, data: array<string, mixed>}
     */
    private function customers(User $user, array $options): array
    {
        $query = $this->dates($user->customers(), $options);
        $this->recordFilters($query, $options, 'customer');
        $count = (clone $query)->count();
        $sentence = 'You currently have '.$count.' '.$this->plural($count, 'customer').' in your MtaaFlow account'.$this->period($options).'.';

        if (($options['mode'] ?? 'count') === 'count' || $count === 0) {
            return ['answer' => $sentence, 'data' => ['query' => 'customers', 'count' => $count]];
        }

        $details = isset($options['customer_id']);
        $targeted = $this->targeted($options);
        $customers = $query->orderBy('name')->orderBy('id')->limit($options['limit'] ?? 20)
            ->get($details ? ['id', 'name', 'email', 'phone', 'address'] : ($targeted ? ['id', 'name'] : ['name']))
            ->map(fn (object $customer): array => [
                ...($targeted ? ['id' => $customer->id] : []),
                'name' => $this->text($customer->name),
                ...($details ? ['email' => $customer->email, 'phone' => $customer->phone, 'address' => $customer->address, 'url' => route('customers.show', $customer)] : []),
            ])->all();

        return [
            'answer' => $sentence."\n\n".$this->listing(array_column($customers, 'name')).$this->truncated(count($customers), $count),
            'data' => ['query' => 'customers', 'count' => $count, 'customers' => $customers],
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{answer: string, data: array<string, mixed>}
     */
    private function invoices(User $user, bool $outstanding, array $options): array
    {
        $query = $this->dates($user->invoices(), $options, 'invoice');
        $this->financialFilters($query, $options);
        $this->invoiceFilters($query, $options);
        $requireBalance = $outstanding || ($options['overdue'] ?? false);

        if ($requireBalance) {
            $query->where('status', 'approved');
        }
        if ($options['overdue'] ?? false) {
            $query->whereDate('due_date', '<', now()->toDateString());
        }

        $count = $requireBalance ? 0 : (clone $query)->count();
        $countOnly = ($options['mode'] ?? 'list') === 'count';
        $limit = $options['limit'] ?? 20;
        $query->with([
            'customer' => fn (BelongsTo $customer): BelongsTo => $customer->where('user_id', $user->id)->select('id', 'name'),
            'payments' => fn (HasMany $payments): HasMany => $payments->where('status', 'completed')->select('id', 'invoice_id', 'amount', 'currency'),
        ])->orderByDesc('created_at')->orderByDesc('id');
        $details = isset($options['invoice_id']);
        if ($details) {
            $query->with('items');
        }
        if ($options['include_sharing'] ?? false) {
            $query->withCount('shares')->with(['shares' => fn (HasMany $shares) => $shares->orderByDesc('id')->limit(20)]);
        }
        $invoices = $requireBalance ? $query->lazy(200) : (($countOnly || $count === 0) ? [] : $query->limit($limit)->get());
        $records = [];
        $lines = [];

        foreach ($invoices as $invoice) {
            $paidMinor = 0;

            foreach ($invoice->payments as $payment) {
                if ($payment->currency === $invoice->currency) {
                    $paidMinor = $this->add($paidMinor, Currency::minor((string) $payment->amount, $invoice->currency));
                }
            }

            $totalMinor = $this->balance->totalMinor($invoice);
            $remainingMinor = max(0, $totalMinor - $paidMinor);

            if ($requireBalance && $remainingMinor === 0) {
                continue;
            }

            if ($requireBalance) {
                $count++;
            }

            if ($countOnly || count($records) >= $limit) {
                continue;
            }

            $remaining = Currency::format($remainingMinor, $invoice->currency);
            $record = [
                'id' => $invoice->id,
                'invoice_number' => $this->text($invoice->invoice_number),
                'customer_name' => $invoice->customer === null ? null : $this->text($invoice->customer->name),
                'currency' => $invoice->currency,
                'total' => Currency::format($totalMinor, $invoice->currency),
                'paid' => Currency::format($paidMinor, $invoice->currency),
                'remaining_balance' => $remaining,
                'status' => $invoice->status,
                'due_date' => $invoice->due_date?->toDateString(),
            ];
            if ($details) {
                $record['customer_id'] = $invoice->customer_id;
                $record['issue_date'] = $invoice->issue_date?->toDateString();
                $record['items'] = $invoice->items->map(fn (InvoiceItem $item): array => [
                    'description' => $this->text($item->description),
                    'quantity' => (int) $item->quantity,
                    'unit_price' => Currency::format(Currency::minor((string) $item->unit_price, $invoice->currency), $invoice->currency),
                ])->all();
            }
            if ($this->targeted($options) || ($options['include_sharing'] ?? false)) {
                $record['url'] = route('invoices.show', $invoice);
            }
            if ($options['include_sharing'] ?? false) {
                $record['shares_count'] = $invoice->shares_count;
                $record['shares'] = $invoice->shares->map(fn (InvoiceShare $share): array => [
                    'id' => $share->id,
                    'expires_at' => $share->expires_at?->toISOString(),
                    'revoked_at' => $share->revoked_at?->toISOString(),
                    'emailed_at' => $share->emailed_at?->toISOString(),
                    'active' => $share->revoked_at === null && ($share->expires_at === null || $share->expires_at->isFuture()),
                ])->all();
            }
            $records[] = $record;
            $lines[] = $record['invoice_number'].($record['customer_name'] === null ? '' : ' for '.$record['customer_name'])
                .' — '.$record['currency'].' '.($outstanding ? $remaining.' outstanding' : $record['total'].' ('.$record['status'].')')
                .($record['due_date'] === null ? '' : '; due '.$record['due_date']).'.';
        }

        $description = $outstanding ? 'approved '.$this->plural($count, 'invoice').' with an outstanding balance' : $this->plural($count, trim(($options['status'] ?? '').' invoice'));
        $sentence = 'You have '.$count.' '.$description.$this->period($options).'.';

        return [
            'answer' => $sentence.($records === [] ? '' : "\n\n".$this->listing($lines).$this->truncated(count($records), $count)),
            'data' => ['query' => $outstanding ? 'outstanding_invoices' : 'invoices', 'count' => $count, 'invoices' => $records],
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{answer: string, data: array<string, mixed>}
     */
    private function payments(User $user, array $options): array
    {
        $query = $this->paymentQuery($user, $options);
        $this->financialFilters($query, $options);
        $count = (clone $query)->count();
        $sentence = 'You have '.$count.' '.$this->plural($count, trim(($options['status'] ?? '').' payment')).$this->period($options).'.';

        if (($options['mode'] ?? 'list') === 'count' || $count === 0) {
            return ['answer' => $sentence, 'data' => ['query' => 'payments', 'count' => $count, 'payments' => []]];
        }

        $targeted = $this->targeted($options);
        $records = $query->with('invoice:id,invoice_number')
            ->orderByRaw('COALESCE(paid_at, payments.created_at) DESC')->orderByDesc('payments.id')
            ->limit($options['limit'] ?? 20)->get(['payments.id', 'invoice_id', 'amount', 'currency', 'status', 'paid_at', 'payments.created_at'])
            ->map(fn (Payment $payment): array => [
                ...($targeted ? ['id' => $payment->id, 'invoice_id' => $payment->invoice_id] : []),
                'invoice_number' => $this->text($payment->invoice->invoice_number),
                'currency' => $payment->currency,
                'amount' => Currency::format(Currency::minor((string) $payment->amount, $payment->currency), $payment->currency),
                'status' => $payment->status,
                'date' => ($payment->paid_at ?? $payment->created_at)?->toDateString(),
            ])->all();
        $lines = array_map(fn (array $record): string => $record['currency'].' '.$record['amount'].' for '.$record['invoice_number']
            .' — '.$record['status'].($record['date'] === null ? '' : ' on '.$record['date']).'.', $records);

        return [
            'answer' => $sentence."\n\nMost recent first:\n".$this->listing($lines).$this->truncated(count($records), $count),
            'data' => ['query' => 'payments', 'count' => $count, 'payments' => $records],
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{answer: string, data: array<string, mixed>}
     */
    private function revenue(User $user, array $options): array
    {
        $query = $this->paymentQuery($user, $options)->where('status', 'completed')
            ->whereHas('invoice', fn (Builder $invoice): Builder => $invoice->whereColumn('invoices.currency', 'payments.currency'));
        $this->financialFilters($query, $options);
        $totals = [];

        foreach ($query->select('payments.id', 'amount', 'currency')->lazyById(500) as $payment) {
            $totals[$payment->currency] = $this->add($totals[$payment->currency] ?? 0, Currency::minor((string) $payment->amount, $payment->currency));
        }

        ksort($totals);
        $records = [];

        foreach ($totals as $currency => $minor) {
            $records[] = ['currency' => $currency, 'total' => Currency::format($minor, $currency)];
        }

        $amounts = array_map(fn (array $record): string => $record['currency'].' '.$record['total'], $records);
        $answer = $records === []
            ? 'You have no verified completed-payment earnings'.$this->period($options).'.'
            : 'Your verified earnings from completed payments'.$this->period($options).' are '.implode('; ', $amounts).'.';

        return ['answer' => $answer, 'data' => ['query' => 'revenue', 'totals_by_currency' => $records]];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{answer: string, data: array<string, mixed>}
     */
    private function products(User $user, bool $lowStock, array $options): array
    {
        $query = $this->dates($user->products(), $options)
            ->when($lowStock, fn ($products) => $products->whereColumn('quantity', '<=', 'low_stock_threshold'));
        $this->recordFilters($query, $options, 'product');
        $count = (clone $query)->count();
        $sentence = 'You have '.$count.' '.$this->plural($count, 'product')
            .($lowStock ? ' at or below '.($count === 1 ? 'its' : 'their').' low-stock threshold' : ' in your inventory').$this->period($options).'.';
        $name = $lowStock ? 'low_stock' : 'products';

        if (($options['mode'] ?? 'list') === 'count' || $count === 0) {
            return ['answer' => $sentence, 'data' => ['query' => $name, 'count' => $count, 'products' => []]];
        }

        $targeted = $this->targeted($options);
        $records = $query->orderBy('name')->orderBy('id')->limit($options['limit'] ?? 20)
            ->get(['id', 'name', 'quantity', 'low_stock_threshold', ...($targeted ? ['sku', 'description'] : [])])
            ->map(fn (object $product): array => [
                'id' => $product->id,
                'name' => $this->text($product->name),
                'quantity' => $product->quantity,
                'low_stock_threshold' => $product->low_stock_threshold,
                ...($targeted ? ['sku' => $product->sku, 'description' => $product->description, 'url' => route('inventory.index')] : []),
            ])->all();

        return [
            'answer' => $sentence."\n\n".$this->listing(array_map(fn (array $product): string => $product['name'].' — '.$product['quantity'].' in stock; low-stock threshold '.$product['low_stock_threshold'].'.', $records)).$this->truncated(count($records), $count),
            'data' => ['query' => $name, 'count' => $count, 'products' => $records],
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{answer: string, data: array<string, mixed>}
     */
    private function reminders(User $user, array $options): array
    {
        $query = $this->dates(PaymentReminder::query()->whereHas('invoice', fn (Builder $invoice): Builder => $invoice->where('user_id', $user->id)), $options);

        if (isset($options['status'])) {
            $query->where('status', $options['status']);
        }

        $count = (clone $query)->count();
        $sentence = 'You have '.$count.' '.$this->plural($count, trim(($options['status'] ?? '').' payment reminder')).$this->period($options).'.';

        if (($options['mode'] ?? 'list') === 'count' || $count === 0) {
            return ['answer' => $sentence, 'data' => ['query' => 'reminders', 'count' => $count, 'reminders' => []]];
        }

        $records = $query->with('invoice:id,invoice_number')->orderByDesc('id')->limit($options['limit'] ?? 20)
            ->get(['id', 'invoice_id', 'status', 'sent_at'])->map(fn (PaymentReminder $reminder): array => [
                'invoice_number' => $this->text($reminder->invoice->invoice_number),
                'status' => $reminder->status,
                'sent_at' => $reminder->sent_at?->toDateString(),
            ])->all();

        return [
            'answer' => $sentence."\n\n".$this->listing(array_map(fn (array $reminder): string => $reminder['invoice_number'].' — '.$reminder['status'].($reminder['sent_at'] === null ? '' : ' on '.$reminder['sent_at']).'.', $records)).$this->truncated(count($records), $count),
            'data' => ['query' => 'reminders', 'count' => $count, 'reminders' => $records],
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{answer: string, data: array<string, mixed>}
     */
    private function summary(User $user, array $options): array
    {
        $invoices = $this->dates($user->invoices(), $options, 'invoice');
        $this->financialFilters($invoices, $options);
        $customers = $this->dates($user->customers(), $options)->count();
        $total = (clone $invoices)->count();
        $paid = (clone $invoices)->where('status', 'paid')->count();
        $approved = (clone $invoices)->where('status', 'approved')->count();
        $products = $this->dates($user->products(), $options);
        $productCount = (clone $products)->count();
        $lowStock = $products->whereColumn('quantity', '<=', 'low_stock_threshold')->count();
        $reminders = $this->dates(PaymentReminder::query()->whereHas('invoice', function (Builder $invoice) use ($user, $options): void {
            $invoice->where('user_id', $user->id);

            if (isset($options['currency'])) {
                $invoice->where('currency', $options['currency']);
            }
        }), $options)->count();
        $earnings = $this->revenue($user, $options);
        $outstanding = $this->invoices($user, true, [...$options, 'mode' => 'count']);

        return [
            'answer' => 'Your business summary'.$this->period($options).":\n\n"
                .$this->listing([
                    $customers.' '.$this->plural($customers, 'customer').'.',
                    $total.' '.$this->plural($total, 'invoice').', including '.$paid.' paid.',
                    $outstanding['data']['count'].' approved '.$this->plural($outstanding['data']['count'], 'invoice').' with an outstanding balance.',
                    $productCount.' inventory '.$this->plural($productCount, 'product').', including '.$lowStock.' at or below '.($lowStock === 1 ? 'its' : 'their').' low-stock threshold.',
                    $reminders.' recorded payment '.$this->plural($reminders, 'reminder').'.',
                    $earnings['answer'],
                ])."\n\nEarnings are completed payments, not profit. Currencies are kept separate.",
            'data' => [
                'query' => 'summary', 'customers' => $customers, 'total_invoices' => $total,
                'paid_invoices' => $paid, 'outstanding_invoices' => $outstanding['data']['count'],
                'totals_by_currency' => $earnings['data']['totals_by_currency'],
                'products' => $productCount, 'low_stock_products' => $lowStock,
                'approved_invoices' => $approved, 'reminders' => $reminders,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function paymentQuery(User $user, array $options): Builder
    {
        return $this->dates(Payment::query()->whereHas('invoice', function (Builder $invoice) use ($user, $options): void {
            $invoice->where('user_id', $user->id);
            $this->invoiceFilters($invoice, $options);
        }), $options, 'payment');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function resolveSelectors(User $user, array $options): array
    {
        foreach (['customer', 'product', 'invoice'] as $kind) {
            $idField = $kind.'_id';
            $nameField = $kind === 'invoice' ? 'invoice_number' : $kind.'_name';

            if (! isset($options[$idField]) && ! isset($options[$nameField])) {
                continue;
            }

            $column = $kind === 'invoice' ? 'invoice_number' : 'name';
            $query = match ($kind) {
                'customer' => $user->customers(),
                'product' => $user->products(),
                'invoice' => $user->invoices(),
            };

            if (isset($options[$idField])) {
                $query->whereKey($options[$idField]);
            }
            if (isset($options[$nameField])) {
                $query->whereRaw('LOWER('.$column.') = ?', [mb_strtolower(trim($options[$nameField]))]);
            }

            $records = $query->orderBy('id')->limit(10)->get(['id', $column]);

            if ($records->count() !== 1) {
                throw ValidationException::withMessages([
                    isset($options[$idField]) ? $idField : $nameField => $records->isEmpty()
                        ? __('No matching record exists in your account.')
                        : __('Choose a record by ID: ').$records->map(fn ($record): string => $record->id.': '.$this->text($record->{$column}))->implode('; '),
                ]);
            }

            $options[$idField] = $records->first()->id;
        }

        if ($this->targeted($options) && ! isset($options['mode'])) {
            $options['mode'] = 'list';
        }

        return $options;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function targeted(array $options): bool
    {
        return array_intersect(['search', 'customer_id', 'customer_name', 'product_id', 'product_name', 'invoice_id', 'invoice_number'], array_keys($options)) !== [];
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function recordFilters(Builder|HasMany $query, array $options, string $kind): void
    {
        if (isset($options[$kind.'_id'])) {
            $query->whereKey($options[$kind.'_id']);
        }

        if (isset($options['search'])) {
            $columns = $kind === 'customer' ? ['name', 'email', 'phone'] : ['name', 'sku', 'description'];
            $term = '%'.mb_strtolower(trim($options['search'])).'%';
            $query->where(function (Builder $matches) use ($columns, $term): void {
                foreach ($columns as $column) {
                    $matches->orWhereRaw('LOWER('.$column.') LIKE ?', [$term]);
                }
            });
        }
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function invoiceFilters(Builder|HasMany $query, array $options): void
    {
        if (isset($options['invoice_id'])) {
            $query->whereKey($options['invoice_id']);
        }
        if (isset($options['customer_id'])) {
            $query->where('customer_id', $options['customer_id']);
        }
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function dates(Builder|HasMany $query, array $options, string $kind = 'record'): Builder|HasMany
    {
        $column = $query->getModel()->qualifyColumn('created_at');

        foreach (['since' => '>=', 'until' => '<='] as $key => $operator) {
            if (! isset($options[$key])) {
                continue;
            }

            if ($kind === 'payment') {
                $query->whereRaw('DATE(COALESCE(paid_at, payments.created_at)) '.$operator.' ?', [$options[$key]]);
            } elseif ($kind === 'invoice') {
                $query->whereRaw('DATE(COALESCE(issue_date, invoices.created_at)) '.$operator.' ?', [$options[$key]]);
            } else {
                $query->whereDate($column, $operator, $options[$key]);
            }
        }

        return $query;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function financialFilters(Builder|HasMany $query, array $options): void
    {
        foreach (['currency', 'status'] as $column) {
            if (isset($options[$column])) {
                $query->where($query->getModel()->qualifyColumn($column), $options[$column]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function period(array $options): string
    {
        $dates = match (true) {
            isset($options['since'], $options['until']) => ' from '.$options['since'].' to '.$options['until'],
            isset($options['since']) => ' since '.$options['since'],
            isset($options['until']) => ' through '.$options['until'],
            default => '',
        };

        return (isset($options['currency']) ? ' in '.$options['currency'] : '').$dates;
    }

    private function plural(int $count, string $singular): string
    {
        return $singular.($count === 1 ? '' : 's');
    }

    private function text(string $value): string
    {
        $value = mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', strip_tags($value)) ?? ''), 0, 255);

        return preg_replace('/([\\\\`*_{}\[\]<>#|!])/u', '\\\\$1', $value) ?? '';
    }

    /**
     * @param  list<string>  $lines
     */
    private function listing(array $lines): string
    {
        return implode("\n", array_map(fn (string $line): string => '- '.$line, $lines));
    }

    private function truncated(int $shown, int $total): string
    {
        return $shown < $total ? "\n\nShowing ".$shown.' of '.$total.' matching records.' : '';
    }

    private function add(int $total, int $amount): int
    {
        if ($amount > PHP_INT_MAX - $total) {
            throw new OverflowException('The payment total exceeds the supported range.');
        }

        return $total + $amount;
    }
}
