<?php

namespace App\Models;

use Database\Factories\InventoryAdjustmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryAdjustment extends Model
{
    /** @use HasFactory<InventoryAdjustmentFactory> */
    use HasFactory;

    protected $fillable = [
        'quantity_before',
        'quantity_change',
        'quantity_after',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'quantity_before' => 'integer',
            'quantity_change' => 'integer',
            'quantity_after' => 'integer',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
