<?php

namespace App\Http\Controllers;

use App\Actions\Inventory\StockAdjuster;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $productsQuery = $request->user()->products()
            ->orderBy('name')
            ->orderBy('id');

        $lowStockCount = (clone $productsQuery)
            ->whereColumn('quantity', '<=', 'low_stock_threshold')
            ->count();

        if ($request->boolean('low_stock_only')) {
            $productsQuery->whereColumn('quantity', '<=', 'low_stock_threshold');
        }

        return response()->json([
            'products' => $productsQuery->get()->map(fn (Product $product): array => $this->productData($product))->values(),
            'low_stock_count' => $lowStockCount,
        ]);
    }

    public function store(Request $request, StockAdjuster $stockAdjuster): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'sku' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('products', 'sku')->where('user_id', $request->user()->id),
            ],
            'description' => ['nullable', 'string', 'max:5000'],
            'quantity' => ['sometimes', 'integer', 'min:0', 'max:2147483647'],
            'low_stock_threshold' => ['sometimes', 'integer', 'min:0', 'max:2147483647'],
        ]);

        $product = DB::transaction(function () use ($request, $stockAdjuster, $validated): Product {
            $product = $request->user()->products()->create([
                'name' => $validated['name'],
                'sku' => $validated['sku'] ?? null,
                'description' => $validated['description'] ?? null,
                'low_stock_threshold' => $validated['low_stock_threshold'] ?? 0,
            ]);

            $initialQuantity = $validated['quantity'] ?? 0;

            if ($initialQuantity > 0) {
                $stockAdjuster->handle($request->user(), $product, $initialQuantity, 'Initial stock');
                $product->refresh();
            }

            return $product;
        });

        return response()->json(['product' => $this->productData($product)], 201);
    }

    public function show(Request $request, Product $product): JsonResponse
    {
        $product = $request->user()->products()->findOrFail($product->id);

        return response()->json(['product' => $this->productData($product)]);
    }

    public function update(Request $request, Product $product): JsonResponse
    {
        $product = $request->user()->products()->findOrFail($product->id);
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'sku' => [
                'sometimes',
                'nullable',
                'string',
                'max:255',
                Rule::unique('products', 'sku')
                    ->where('user_id', $request->user()->id)
                    ->ignore($product->id),
            ],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'low_stock_threshold' => ['sometimes', 'required', 'integer', 'min:0', 'max:2147483647'],
        ]);

        $product->update($validated);

        return response()->json(['product' => $this->productData($product->refresh())]);
    }

    public function destroy(Request $request, Product $product): JsonResponse
    {
        $product = $request->user()->products()->findOrFail($product->id);
        $product->delete();

        return response()->json([], 204);
    }

    /**
     * @return array<string, int|string|bool|null>
     */
    private function productData(Product $product): array
    {
        return [
            'id' => $product->id,
            'name' => $product->name,
            'sku' => $product->sku,
            'description' => $product->description,
            'quantity' => $product->quantity,
            'low_stock_threshold' => $product->low_stock_threshold,
            'is_low_stock' => $product->quantity <= $product->low_stock_threshold,
            'created_at' => $product->created_at?->toISOString(),
            'updated_at' => $product->updated_at?->toISOString(),
        ];
    }
}
