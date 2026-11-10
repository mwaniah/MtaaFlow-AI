<?php

namespace App\Http\Controllers;

use App\Actions\Inventory\StockAdjuster;
use App\Models\InventoryAdjustment;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InventoryAdjustmentController extends Controller
{
    public function index(Request $request, Product $product): JsonResponse
    {
        $product = $request->user()->products()->findOrFail($product->id);

        return response()->json([
            'product_id' => $product->id,
            'adjustments' => $product->adjustments()
                ->orderByDesc('id')
                ->paginate(50)
                ->through(fn (InventoryAdjustment $adjustment): array => $this->adjustmentData($adjustment)),
        ]);
    }

    public function store(Request $request, Product $product, StockAdjuster $stockAdjuster): JsonResponse
    {
        $product = $request->user()->products()->findOrFail($product->id);
        $validated = $request->validate([
            'quantity_change' => ['required', 'integer', 'between:-2147483647,2147483647', 'not_in:0'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $adjustment = $stockAdjuster->handle(
            $request->user(),
            $product,
            (int) $validated['quantity_change'],
            $validated['notes'] ?? null,
        );

        return response()->json([
            'adjustment' => $this->adjustmentData($adjustment),
            'product' => [
                'id' => $product->id,
                'quantity' => $product->refresh()->quantity,
            ],
        ], 201);
    }

    /**
     * @return array<string, int|string|null>
     */
    private function adjustmentData(InventoryAdjustment $adjustment): array
    {
        return [
            'id' => $adjustment->id,
            'product_id' => $adjustment->product_id,
            'quantity_before' => $adjustment->quantity_before,
            'quantity_change' => $adjustment->quantity_change,
            'quantity_after' => $adjustment->quantity_after,
            'notes' => $adjustment->notes,
            'created_at' => $adjustment->created_at?->toISOString(),
        ];
    }
}
