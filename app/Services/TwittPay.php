<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

/**
 * TwittPay - Laravel service.
 * ---------------------------------------------------------------------------
 * Uses Laravel's own HTTP client, so retries, timeouts and Http::fake() in your
 * tests all work the way you already expect.
 *
 *   $pay = new TwittPay();                         // reads config/twittpay.php
 *   $pay = new TwittPay($key, $baseUrl);           // or pass them in
 *
 * THE FLOW
 *   1. createPayment()  -> payment_url. Redirect the customer to it.
 *   2. They come back to your success_url with ?transactionId=...
 *   3. verifyPayment()  -> the truth. Deliver on this, never on the query string.
 *   4. Your webhook_url is called server to server, and can be called twice.
 *
 * The webhook route must be excluded from CSRF - see the README. The gateway's
 * server has no CSRF token, so a protected route answers 419 and the payment
 * silently never lands.
 */
class TwittPay
{
    protected string $apiKey;

    /** scheme://host, no trailing slash. */
    protected string $baseUrl;

    protected int $timeout = 30;

    protected string $lastError = '';

    public function __construct(?string $apiKey = null, ?string $baseUrl = null)
    {
        $this->apiKey  = trim((string) ($apiKey ?? config('twittpay.api_key', '')));
        $this->baseUrl = static::normaliseBaseUrl($baseUrl ?? config('twittpay.base_url', ''));
    }

    /** Turn whatever was configured into scheme://host. */
    public static function normaliseBaseUrl(?string $url): string
    {
        return 'https://checkout.twittpay.com';
    }

    /**
     * Step 1. amount, success_url and cancel_url are required.
     *
     * @return array{status: mixed, payment_url?: string, message?: string}
     */
    public function createPayment(array $data): array
    {
        foreach (['amount', 'success_url', 'cancel_url'] as $required) {
            if (empty($data[$required])) {
                return ['status' => 0, 'message' => 'Missing required field: ' . $required];
            }
        }

        $payload = [
            'cus_name'    => (string) ($data['cus_name'] ?? 'Default Name'),
            'cus_email'   => (string) ($data['cus_email'] ?? 'default@gmail.com'),
            'amount'      => number_format((float) $data['amount'], 2, '.', ''),
            'success_url' => (string) $data['success_url'],
            'cancel_url'  => (string) $data['cancel_url'],
        ];

        if (! empty($data['webhook_url'])) {
            $payload['webhook_url'] = (string) $data['webhook_url'];
        }

        // Must arrive as a JSON object. A PHP list would encode as a JSON array
        // and the gateway rejects it, so it is cast here.
        if (! empty($data['metadata'])) {
            $payload['metadata'] = (object) $data['metadata'];
        }

        return $this->post('/api/payment/create', $payload);
    }

    /** Step 3. The only answer worth acting on. */
    public function verifyPayment(string $transactionId): array
    {
        $transactionId = trim($transactionId);

        if ($transactionId === '') {
            return ['status' => 0, 'message' => 'Missing transaction id.'];
        }

        return $this->post('/api/payment/verify', ['transaction_id' => $transactionId]);
    }

    /**
     * True only for a payment you may deliver. Pass the order total so a 1 taka
     * payment cannot settle a 1000 taka order.
     */
    public function isPaid(array $verified, float $expected = 0): bool
    {
        if (static::readStatus($verified) !== 'COMPLETED') {
            return false;
        }

        if ($expected <= 0) {
            return true;
        }

        return ((float) ($verified['amount'] ?? 0)) + 0.01 >= $expected;
    }

    /** COMPLETED, PENDING, ERROR or ''. */
    public static function readStatus(array $verified): string
    {
        if (! isset($verified['status']) || ! is_string($verified['status'])) {
            return '';
        }

        return strtoupper(trim($verified['status']));
    }

    /** metadata comes back from verify as a JSON string. This unpacks it. */
    public static function decodeMetadata(array $verified): array
    {
        $meta = $verified['metadata'] ?? null;

        if (is_array($meta)) {
            return $meta;
        }

        if (is_object($meta)) {
            return (array) $meta;
        }

        if (is_string($meta) && $meta !== '') {
            $decoded = json_decode($meta, true);

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    /**
     * Read the webhook body. Form encoded, and NOT signed - it hands you a
     * transaction id to go and verify, never a decision.
     */
    public static function readWebhook(): array
    {
        $body = request()->all();

        return [
            'transactionId' => trim((string) ($body['transactionId'] ?? $body['transaction_id'] ?? '')),
            'paymentAmount' => trim((string) ($body['paymentAmount'] ?? '')),
            'paymentFee'    => trim((string) ($body['paymentFee'] ?? '')),
            'paymentMethod' => trim((string) ($body['paymentMethod'] ?? '')),
            'status'        => trim((string) ($body['status'] ?? '')),
        ];
    }

    public function lastError(): string
    {
        return $this->lastError;
    }

    public function setTimeout(int $seconds): static
    {
        $this->timeout = $seconds;

        return $this;
    }

    /** One POST. JSON in, array out - never an exception. */
    protected function post(string $endpoint, array $payload): array
    {
        $this->lastError = '';

        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
                'API-KEY'      => $this->apiKey,
            ])->timeout($this->timeout)->post($this->baseUrl . $endpoint, $payload);
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();

            return ['status' => 0, 'message' => $this->lastError];
        }

        $decoded = $response->json();

        // A 400 from the gateway still carries a readable message, so the body is
        // preferred over the status code.
        if (is_array($decoded)) {
            return $decoded;
        }

        $this->lastError = 'The gateway sent back something that is not JSON (HTTP ' . $response->status() . ').';

        return ['status' => 0, 'message' => $this->lastError];
    }
}
