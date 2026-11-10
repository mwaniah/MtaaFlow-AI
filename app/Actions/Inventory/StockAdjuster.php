<?php

namespace App\Actions\Inventory;

use App\Models\InventoryAdjustment;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StockAdjuster
{
    private const MAX_QUANTITY = 2_147_483_647;

    public function handle(
        User $user,
        Product $product,
        int $quantityChange,
        ?string $notes = null,
    ): InventoryAdjustment {
        if ($quantityChange === 0) {
            throw ValidationException::withMessages([
                'quantity_change' => 'The quantity change must not be zero.',
            ]);
        }

        return DB::transaction(function () use ($user, $product, $quantityChange, $notes): InventoryAdjustment {
            $lockedProduct = $user->products()
                ->whereKey($product->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $quantityBefore = $lockedProduct->quantity;
            $quantityAfter = $quantityBefore + $quantityChange;

            if ($quantityAfter < 0) {
                throw ValidationException::withMessages([
                    'quantity_change' => 'The adjustment cannot reduce stock below zero.',
                ]);
            }

            if ($quantityAfter > self::MAX_QUANTITY) {
                throw ValidationException::withMessages([
                    'quantity_change' => 'The adjustment exceeds the maximum supported stock quantity.',
                ]);
            }

            $lockedProduct->quantity = $quantityAfter;
            $lockedProduct->save();

            return $lockedProduct->adjustments()->create([
                'quantity_before' => $quantityBefore,
                'quantity_change' => $quantityChange,
                'quantity_after' => $quantityAfter,
                'notes' => $notes,
            ]);
        });
    }
}
