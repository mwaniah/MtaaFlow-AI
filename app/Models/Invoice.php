<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function items()
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function reminders()
    {
        return $this->hasMany(PaymentReminder::class);
    }
}
