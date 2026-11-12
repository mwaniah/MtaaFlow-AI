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
            && config('services.paypal.client_secret') !== ''
            && $this->hasValidEndpoint();
    }

    private function hasValidEndpoint(): bool
    {
        $baseUrls = match (config('services.paypal.mode', 'sandbox')) {
            'sandbox' => ['https://api-m.sandbox.paypal.com', 'https://api.sandbox.paypal.com'],
            'live' => ['https://api-m.paypal.com', 'https://api.paypal.com'],
            default => [],
        };

        return in_array(rtrim((string) config('services.paypal.base_url'), '/'), $baseUrls, true);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getOrder(Payment $payment): ?array
    {
        try {
            $order = $this->send(
                '/v2/checkout/orders/'.rawurlencode((string) $payment->paypal_order_id),
                [],
                (string) $payment->paypal_capture_request_id,
                'GET'
            );
        } catch (PayPalGatewayException $exception) {
            if ($exception->providerStatus === 404 && $exception->providerIssue === 'INVALID_RESOURCE_ID') {
                return null;
            }

            throw $exception;
        }

        if (($order['id'] ?? null) !== $payment->paypal_order_id) {
            throw new PayPalGatewayException(__('PayPal could not verify the captured payment.'), 502);
        }

        return $order;
    }

    /**
     * @param  array<string, mixed>  $order
     */
    public function approvalUrl(array $order): string
    {
        $link = collect($order['links'] ?? [])->first(fn (mixed $link): bool => is_array($link)
            && in_array($link['rel'] ?? null, ['approve', 'payer-action'], true));
        $url = is_array($link) ? ($link['href'] ?? null) : null;
        $parts = is_string($url) ? parse_url($url) : false;
        $hosts = config('services.paypal.mode', 'sandbox') === 'sandbox'
            ? ['www.sandbox.paypal.com', 'sandbox.paypal.com']
            : ['www.paypal.com', 'paypal.com'];
        parse_str(is_array($parts) ? ($parts['query'] ?? '') : '', $query);

        if (
            ! is_string($order['id'] ?? null) || $order['id'] === ''
            || ! is_array($parts) || ($parts['scheme'] ?? null) !== 'https'
            || ! in_array($parts['host'] ?? null, $hosts, true)
            || isset($parts['user']) || isset($parts['pass'])
            || (isset($parts['port']) && $parts['port'] !== 443)
            || ($query['token'] ?? null) !== $order['id']
        ) {
            throw new PayPalGatewayException(
                __('PayPal returned an incomplete checkout response. Retry with the same idempotency key.'),
                502
            );
        }

        return $url;
    }

    /**
     * @return array<string, mixed>
     */
    public function createOrder(Payment $payment, Invoice $invoice, ?string $returnUrl = null, ?string $cancelUrl = null): array
    {
        $amount = Currency::format(
            Currency::minor((string) $payment->amount, $invoice->currency),
            $invoice->currency
        );

        $payload = [
            'intent' => 'CAPTURE',
            'purchase_units' => [
                [
                    'reference_id' => 'invoice-'.$invoice->id,
                    'custom_id' => 'payment-'.$payment->id,
                    'description' => 'Invoice '.$invoice->invoice_number,
                    'amount' => [
                        'currency_code' => $invoice->currency,
                        'value' => $amount,
                    ],
                ],
            ],
            'application_context' => [
                'user_action' => 'PAY_NOW',
            ],
        ];

        if ($returnUrl !== null && $cancelUrl !== null) {
            unset($payload['application_context']);
            $payload['payment_source'] = [
                'paypal' => [
                    'experience_context' => [
                        'user_action' => 'PAY_NOW',
                        'return_url' => $returnUrl,
                        'cancel_url' => $cancelUrl,
                    ],
                ],
            ];
        }

        return $this->send('/v2/checkout/orders', $payload, $payment->idempotency_key);
    }

    /**
     * @return array<string, mixed>
     */
    public function captureOrder(Payment $payment): array
    {
        try {
            $response = $this->send(
                '/v2/checkout/orders/'.rawurlencode((string) $payment->paypal_order_id).'/capture',
                [],
                (string) $payment->paypal_capture_request_id
            );
        } catch (PayPalGatewayException $exception) {
            if ($exception->providerIssue !== 'ORDER_ALREADY_CAPTURED') {
                throw $exception;
            }

            $response = $this->getOrder($payment) ?? [];
            $this->verifyCapture($response, $payment, $payment->invoice);

            return $response;
        }
        $purchaseUnit = $response['purchase_units'][0] ?? [];

        if (
            ($response['status'] ?? null) === 'COMPLETED'
            && (! isset($purchaseUnit['custom_id']) || ! isset($purchaseUnit['amount']))
        ) {
            $details = $this->getOrder($payment) ?? [];
            $captureId = $this->verifyCapture($details, $payment, $payment->invoice);
            $responseCaptureId = $purchaseUnit['payments']['captures'][0]['id'] ?? null;

            if (
                ($details['id'] ?? null) !== $payment->paypal_order_id
                || (isset($response['id']) && $response['id'] !== $payment->paypal_order_id)
                || ($responseCaptureId !== null && $responseCaptureId !== $captureId)
            ) {
                throw new PayPalGatewayException(__('PayPal could not verify the captured payment.'), 502);
            }

            if ($responseCaptureId === null) {
                return $details;
            }

            $response['purchase_units'][0]['custom_id'] ??= $details['purchase_units'][0]['custom_id'];
            $response['purchase_units'][0]['amount'] ??= $details['purchase_units'][0]['amount'];
        }

        return $response;
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
            || ($response['id'] ?? null) !== $payment->paypal_order_id
            || ! is_array($response['purchase_units'] ?? null)
            || count($response['purchase_units'] ?? []) !== 1
            || ! is_array($purchaseUnit)
            || ($purchaseUnit['reference_id'] ?? null) !== 'invoice-'.$invoice->id
            || ($purchaseUnit['custom_id'] ?? null) !== 'payment-'.$payment->id
            || ! is_array($capture)
            || count($purchaseUnit['payments']['captures'] ?? []) !== 1
            || ($capture['status'] ?? null) !== 'COMPLETED'
            || ! is_string($capture['id'] ?? null)
            || $capture['id'] === ''
            || $payment->currency !== $invoice->currency
            || ! $this->amountMatches($purchaseUnit['amount'] ?? null, $payment, $invoice)
            || ! $this->amountMatches($capture['amount'] ?? null, $payment, $invoice)
        ) {
            throw new PayPalGatewayException(__('PayPal could not verify the captured payment.'), 502);
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
    private function send(string $path, array $payload, string $requestId, string $method = 'POST'): array
    {
        $accessToken = $this->accessToken();

        try {
            $response = Http::connectTimeout(10)
                ->timeout(45)
                ->acceptJson()
                ->withToken($accessToken)
                ->withHeaders(['PayPal-Request-Id' => $requestId, 'Prefer' => 'return=representation'])
                ->send(
                    $method,
                    rtrim((string) config('services.paypal.base_url'), '/').$path,
                    $method === 'GET'
                    ? []
                    : ['json' => $payload === [] ? new \stdClass : $payload]
                );
        } catch (ConnectionException $exception) {
            throw new PayPalGatewayException(
                __('PayPal could not be reached. Retry the same payment request.'),
                502,
                $exception
            );
        }

        if ($response->failed()) {
            $status = $response->status();
            $issue = $response->json('details.0.issue');
            $message = $status >= 400 && $status < 500 && $status !== 429
                ? __('PayPal rejected the payment request.')
                : __('PayPal is temporarily unavailable.');
            $httpStatus = $status >= 400 && $status < 500 && $status !== 429 ? 422 : 502;

            throw new PayPalGatewayException(
                $message,
                $httpStatus,
                providerStatus: $status,
                providerIssue: is_string($issue) ? $issue : null,
                debugId: $response->header('PayPal-Debug-Id')
            );
        }

        $data = $response->json();

        if (! is_array($data)) {
            throw new PayPalGatewayException(__('PayPal returned an invalid response.'), 502);
        }

        return $data;
    }

    private function accessToken(): string
    {
        if (! $this->isConfigured()) {
            throw new PayPalGatewayException(__('PayPal Sandbox credentials are not configured.'), 503);
        }

        $clientId = (string) config('services.paypal.client_id');
        $clientSecret = (string) config('services.paypal.client_secret');
        $cacheKey = 'paypal.oauth.'.hash('sha256', config('services.paypal.base_url').'|'.$clientId.'|'.$clientSecret);
        $cachedToken = Cache::get($cacheKey);

        if (is_string($cachedToken) && $cachedToken !== '') {
            return $cachedToken;
        }

        try {
            $response = Http::connectTimeout(10)
                ->timeout(20)
                ->acceptJson()
                ->withBasicAuth($clientId, $clientSecret)
                ->asForm()
                ->post(rtrim((string) config('services.paypal.base_url'), '/').'/v1/oauth2/token', [
                    'grant_type' => 'client_credentials',
                ]);
        } catch (ConnectionException $exception) {
            throw new PayPalGatewayException(__('Unable to connect to PayPal.'), 502, $exception);
        }

        if ($response->failed()) {
            throw new PayPalGatewayException(__('PayPal authentication failed. Verify the configured credentials.'), 503);
        }

        $data = $response->json();

        $accessToken = is_array($data) ? ($data['access_token'] ?? null) : null;
        $expiresIn = is_array($data) ? (int) ($data['expires_in'] ?? 0) : 0;

        if (! is_string($accessToken) || $accessToken === '' || $expiresIn < 2) {
            throw new PayPalGatewayException(__('PayPal returned an incomplete authentication response.'), 502);
        }

        Cache::put($cacheKey, $accessToken, max(1, $expiresIn - 60));

        return $accessToken;
    }
}
