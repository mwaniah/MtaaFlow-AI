<?php

namespace App\Actions\Invoices;

use App\Models\InvoiceShare;
use Illuminate\Database\Eloquent\Builder;

class ResolveInvoiceShare
{
    public function handle(string $token): InvoiceShare
    {
        $share = InvoiceShare::query()
            ->where('token_hash', hash('sha256', $token))
            ->whereNull('revoked_at')
            ->where(fn (Builder $query) => $query
                ->whereNull('expires_at')
                ->orWhere('expires_at', '>', now()))
            ->with('invoice.customer')
            ->firstOrFail();

        abort_unless($share->invoice?->customer?->user_id === $share->invoice?->user_id, 404);
        abort_if($share->invoice === null || $share->invoice->customer === null, 404);

        return $share;
    }
}
