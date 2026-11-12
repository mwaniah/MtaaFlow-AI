<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    protected $fillable = [
        'user_id',
        'customer_id',
        'invoice_number',
        'currency',
        'subtotal',
        'total',
        'status',
        'issue_date',
        'due_date',
        'submission_id',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:4',
            'total' => 'decimal:4',
            'issue_date' => 'date',
            'due_date' => 'date',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return HasMany<InvoiceItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /** @return HasMany<PaymentReminder, $this> */
    public function reminders(): HasMany
    {
        return $this->hasMany(PaymentReminder::class);
    }

    /** @return HasMany<InvoiceShare, $this> */
    public function shares(): HasMany
    {
        return $this->hasMany(InvoiceShare::class);
    }
}
