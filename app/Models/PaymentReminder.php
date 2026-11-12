<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentReminder extends Model
{
    protected $fillable = [
        'invoice_id',
        'message',
        'status',
        'delivery_channel',
        'sent_at',
        'prepared_at',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'prepared_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
