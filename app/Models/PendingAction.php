<?php

namespace App\Models;

use Database\Factories\PendingActionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PendingAction extends Model
{
    /** @use HasFactory<PendingActionFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'token_hash',
        'type',
        'payload',
        'result',
        'expires_at',
        'confirmed_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'result' => 'array',
            'expires_at' => 'datetime',
            'confirmed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
