<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $payments = Payment::query()
            ->whereHas('invoice', fn (Builder $query) => $query->where('user_id', $request->user()->id))
            ->with(['invoice.customer'])
            ->latest()
            ->get();

        return response()->json([
            'payments' => $payments,
        ]);
    }

    public function show(Request $request, Payment $payment): JsonResponse
    {
        $payment = Payment::query()
            ->whereKey($payment->getKey())
            ->whereHas('invoice', fn (Builder $query) => $query->where('user_id', $request->user()->id))
            ->with(['invoice.customer'])
            ->firstOrFail();

        return response()->json([
            'payment' => $payment,
        ]);
    }
}
