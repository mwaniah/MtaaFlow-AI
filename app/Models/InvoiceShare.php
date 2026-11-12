<?php

namespace App\Models;

use Database\Factories\InvoiceShareFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceShare extends Model
{
    /** @use HasFactory<InvoiceShareFactory> */
    use HasFactory;

    protected $fillable = [
        'invoice_id',
        'token_hash',
        'expires_at',
        'revoked_at',
        'emailed_at',
        'email_sent_to',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'emailed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
