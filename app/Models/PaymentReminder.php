<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }
}
