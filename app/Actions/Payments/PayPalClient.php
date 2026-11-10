<?php

namespace App\Actions\Payments;

use App\Currency;
use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

class PayPalClient
{
    public function isConfigured(): bool
    {
        return is_string(config('services.paypal.client_id'))
            && config('services.paypal.client_id') !== ''
            && is_string(config('services.paypal.client_secret'))
            && config('services.paypal.client_secret') !== '';
    }

    /**
     * @return array<string, mixed>
     */
    public function createOrder(Payment $payment, Invoice $invoice): array
    {
        $amount = Currency::format(
            Currency::minor((string) $payment->amount, $invoice->currency),
            $invoice->currency
        );

        return $this->send('/v2/checkout/orders', [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'reference_id' => 'invoice-'.$invoice->id,
                'custom_id' => 'payment-'.$payment->id,
                'description' => 'Invoice '.$invoice->invoice_number,
                'amount' => [
                    'currency_code' => $invoice->currency,
                    'value' => $amount,
                ],
            ]],
            'application_context' => [
                'user_action' => 'PAY_NOW',
            ],
        ], $payment->idempotency_key);
    }

    /**
     * @return array<string, mixed>
     */
    public function captureOrder(Payment $payment): array
    {
        return $this->send(
            '/v2/checkout/orders/'.rawurlencode((string) $payment->paypal_order_id).'/capture',
            [],
            (string) $payment->paypal_capture_request_id
        );
    }

    /**
     * @param  array<string, mixed>  $response
     */
    public function verifyCapture(array $response, Payment $payment, Invoice $invoice): string
    {
        $purchaseUnit = $response['purchase_units'][0] ?? null;
        $capture = is_array($purchaseUnit)
            ? ($purchaseUnit['payments']['captures'][0] ?? null)
            : null;

        if (
            ($response['status'] ?? null) !== 'COMPLETED'
            || ! is_array($purchaseUnit)
            || ($purchaseUnit['reference_id'] ?? null) !== 'invoice-'.$invoice->id
            || ($purchaseUnit['custom_id'] ?? null) !== 'payment-'.$payment->id
            || ! is_array($capture)
            || ($capture['status'] ?? null) !== 'COMPLETED'
            || ! is_string($capture['id'] ?? null)
            || $capture['id'] === ''
            || ! $this->amountMatches($purchaseUnit['amount'] ?? null, $payment, $invoice)
            || ! $this->amountMatches($capture['amount'] ?? null, $payment, $invoice)
        ) {
            throw new PayPalGatewayException('PayPal could not verify the captured payment.', 502);
        }

        return $capture['id'];
    }

    private function amountMatches(mixed $amount, Payment $payment, Invoice $invoice): bool
    {
        if (
            ! is_array($amount)
            || ($amount['currency_code'] ?? null) !== $invoice->currency
            || ! is_string($amount['value'] ?? null)
        ) {
            return false;
        }

        try {
            return Currency::minor($amount['value'], $invoice->currency)
                === Currency::minor((string) $payment->amount, $invoice->currency);
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function send(string $path, array $payload, string $requestId): array
    {
        $accessToken = $this->accessToken();

        try {
            $response = Http::connectTimeout(10)
                ->timeout(45)
                ->withToken($accessToken)
                ->withHeaders(['PayPal-Request-Id' => $requestId])
                ->post(rtrim((string) config('services.paypal.base_url'), '/').$path, $payload);
        } catch (ConnectionException $exception) {
            throw new PayPalGatewayException(
                'PayPal could not be reached. Retry the same payment request.',
                502,
                $exception
            );
        }

        if ($response->failed()) {
            $status = $response->status();
            $message = $status >= 400 && $status < 500 && $status !== 429
                ? 'PayPal rejected the payment request.'
                : 'PayPal is temporarily unavailable.';
            $httpStatus = $status >= 400 && $status < 500 && $status !== 429 ? 422 : 502;

            throw new PayPalGatewayException($message, $httpStatus);
        }

        $data = $response->json();

        if (! is_array($data)) {
            throw new PayPalGatewayException('PayPal returned an invalid response.', 502);
        }

        return $data;
    }

    private function accessToken(): string
    {
        if (! $this->isConfigured()) {
            throw new PayPalGatewayException('PayPal Sandbox credentials are not configured.', 503);
        }

        $clientId = (string) config('services.paypal.client_id');
        $clientSecret = (string) config('services.paypal.client_secret');
        $cacheKey = 'paypal.oauth.'.hash('sha256', $clientId);
        $cachedToken = Cache::get($cacheKey);

        if (is_string($cachedToken) && $cachedToken !== '') {
            return $cachedToken;
        }

        try {
            $response = Http::connectTimeout(10)
                ->timeout(20)
                ->withBasicAuth($clientId, $clientSecret)
                ->asForm()
                ->post(rtrim((string) config('services.paypal.base_url'), '/').'/v1/oauth2/token', [
                    'grant_type' => 'client_credentials',
                ]);
        } catch (ConnectionException $exception) {
            throw new PayPalGatewayException('Unable to connect to PayPal.', 502, $exception);
        }

        if ($response->failed()) {
            throw new PayPalGatewayException('PayPal authentication failed. Verify the configured credentials.', 503);
        }

        $data = $response->json();

        $accessToken = is_array($data) ? ($data['access_token'] ?? null) : null;
        $expiresIn = is_array($data) ? (int) ($data['expires_in'] ?? 0) : 0;

        if (! is_string($accessToken) || $accessToken === '' || $expiresIn < 2) {
            throw new PayPalGatewayException('PayPal returned an incomplete authentication response.', 502);
        }

        Cache::put($cacheKey, $accessToken, max(1, $expiresIn - 60));

        return $accessToken;
    }
}
