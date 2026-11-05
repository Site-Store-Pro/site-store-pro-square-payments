<?php

namespace PaymentProcessors\Square;

use App\Models\OrderPayment;
use App\Services\Payments\Contracts\PaymentPluginInterface;
use App\Services\Payments\PaymentResult;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * SquareProcessor — Stand-alone Square Payments plugin for Site Store Pro.
 *
 * Uses the Square Web Payments SDK (v1/square.js) on the frontend and
 * Square REST API v2 (/v2/payments, /v2/refunds, /v2/locations) on the server.
 * Requires zero external Composer dependencies.
 *
 * Auto-discovered via `payment-processors/square/processor.json` (processor_id = 100).
 */
class SquareProcessor implements PaymentPluginInterface
{
    /**
     * Currencies that do not use decimal subdivisions (0 decimal places in Square API).
     */
    private const ZERO_DECIMAL_CURRENCIES = [
        'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW',
        'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF',
    ];

    public function __construct(private readonly bool $sandbox = true) {}

    // ─────────────────────────────────────────────────────────────────────────
    // PaymentProcessorInterface implementation
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Create and complete a payment with Square REST API v2 (/v2/payments)
     * using the tokenized source_id returned by Square Web Payments SDK.
     *
     * Expected $payload:
     *   - 'source_id'          => 'cnon:...' (required)
     *   - 'verification_token' => 'verf:...' (optional, 3DS/SCA token)
     *   - 'buyer_email'        => 'customer@example.com' (optional)
     */
    public function charge(float $amount, string $currency, array $payload): PaymentResult
    {
        $sourceId = trim((string) ($payload['source_id'] ?? ''));

        if (empty($sourceId)) {
            return new PaymentResult(
                success:           false,
                authorizationCode: '',
                errorMessage:      'No Square payment token (source_id) received from checkout.',
                processorName:     $this->getName(),
            );
        }

        try {
            $accessToken = $this->getAccessToken();
            $locationId  = $this->getLocationId();
            $baseUrl     = $this->getBaseUrl();
            $curr        = strtoupper(trim($currency ?: 'USD'));

            $requestBody = [
                'source_id'       => $sourceId,
                'idempotency_key' => (string) Str::uuid(),
                'amount_money'    => [
                    'amount'   => $this->toSmallestCurrencyUnit($amount, $curr),
                    'currency' => $curr,
                ],
                'autocomplete'    => true,
                'location_id'     => $locationId,
                'note'            => 'Order from ' . config('app.name', 'Online Store'),
            ];

            if (!empty($payload['verification_token'])) {
                $requestBody['verification_token'] = (string) $payload['verification_token'];
            }

            if (!empty($payload['buyer_email']) && filter_var($payload['buyer_email'], FILTER_VALIDATE_EMAIL)) {
                $requestBody['buyer_email_address'] = (string) $payload['buyer_email'];
            }

            $response = Http::withToken($accessToken)
                ->withHeaders([
                    'Square-Version' => $this->getApiVersion(),
                    'Content-Type'   => 'application/json',
                    'Accept'         => 'application/json',
                ])
                ->post("{$baseUrl}/v2/payments", $requestBody);

            if ($response->failed()) {
                $errorMsg = $this->extractSquareErrorMessage($response->json(), 'Payment authorization failed.');
                Log::warning("[SquareProcessor] /v2/payments failed ({$response->status()}): " . $response->body());

                return new PaymentResult(
                    success:           false,
                    authorizationCode: '',
                    errorMessage:      "Square Error: {$errorMsg}",
                    processorName:     $this->getName(),
                );
            }

            $payment = $response->json('payment') ?? [];
            $status  = strtoupper((string) ($payment['status'] ?? ''));

            if (!in_array($status, ['COMPLETED', 'APPROVED'], true)) {
                return new PaymentResult(
                    success:           false,
                    authorizationCode: '',
                    transactionId:     (string) ($payment['id'] ?? ''),
                    errorMessage:      'Square payment status: ' . ($status ?: 'UNKNOWN'),
                    processorName:     $this->getName(),
                );
            }

            $paymentId     = (string) ($payment['id'] ?? '');
            $receiptNumber = (string) ($payment['receipt_number'] ?? '');
            $transactionId = $receiptNumber !== ''
                ? "Square Payment: {$paymentId} (Receipt: {$receiptNumber})"
                : $paymentId;

            return new PaymentResult(
                success:           true,
                authorizationCode: $paymentId,
                transactionId:     $transactionId,
                processorName:     $this->getName(),
            );
        } catch (\Throwable $e) {
            Log::error('[SquareProcessor] charge exception: ' . $e->getMessage());

            return new PaymentResult(
                success:           false,
                authorizationCode: '',
                errorMessage:      'Square payment failed: ' . $e->getMessage(),
                processorName:     $this->getName(),
            );
        }
    }

    public function isSandbox(): bool
    {
        return $this->sandbox;
    }

    public function getName(): string
    {
        return 'Square Payments' . ($this->sandbox ? ' (Sandbox)' : '');
    }

    /**
     * Process a partial or full refund via Square REST API v2 (POST /v2/refunds).
     */
    public function refund(string $transactionId, float $amount, ?string $reason = null, string $currency = 'USD'): PaymentResult
    {
        $paymentId = $this->extractPaymentId($transactionId);

        if (empty($paymentId)) {
            return new PaymentResult(
                success:           false,
                authorizationCode: '',
                errorMessage:      'No Square payment ID provided for refund.',
                processorName:     $this->getName(),
            );
        }

        try {
            $accessToken = $this->getAccessToken();
            $baseUrl     = $this->getBaseUrl();
            $curr        = strtoupper(trim($currency ?: 'USD'));

            $requestBody = [
                'idempotency_key' => (string) Str::uuid(),
                'payment_id'      => $paymentId,
                'amount_money'    => [
                    'amount'   => $this->toSmallestCurrencyUnit($amount, $curr),
                    'currency' => $curr,
                ],
            ];

            if (!empty($reason)) {
                $requestBody['reason'] = mb_substr(trim($reason), 0, 192);
            }

            $response = Http::withToken($accessToken)
                ->withHeaders([
                    'Square-Version' => $this->getApiVersion(),
                    'Content-Type'   => 'application/json',
                    'Accept'         => 'application/json',
                ])
                ->post("{$baseUrl}/v2/refunds", $requestBody);

            if ($response->failed()) {
                $errorMsg = $this->extractSquareErrorMessage($response->json(), 'Refund request failed.');
                Log::error("[SquareProcessor] /v2/refunds failed for {$paymentId} ({$response->status()}): " . $response->body());

                return new PaymentResult(
                    success:           false,
                    authorizationCode: '',
                    errorMessage:      "Square Refund Error: {$errorMsg}",
                    processorName:     $this->getName(),
                );
            }

            $refund   = $response->json('refund') ?? [];
            $status   = strtoupper((string) ($refund['status'] ?? ''));
            $refundId = (string) ($refund['id'] ?? ('SQ-RFND-' . strtoupper(Str::random(8))));

            if (in_array($status, ['REJECTED', 'FAILED'], true)) {
                return new PaymentResult(
                    success:           false,
                    authorizationCode: '',
                    errorMessage:      "Square Refund {$status}.",
                    processorName:     $this->getName(),
                );
            }

            return new PaymentResult(
                success:           true,
                authorizationCode: $refundId,
                transactionId:     $refundId,
                processorName:     $this->getName(),
            );
        } catch (\Throwable $e) {
            Log::error("[SquareProcessor] refund exception for {$paymentId}: " . $e->getMessage());

            return new PaymentResult(
                success:           false,
                authorizationCode: '',
                errorMessage:      'Square Refund Failed: ' . $e->getMessage(),
                processorName:     $this->getName(),
            );
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PaymentPluginInterface implementation
    // ─────────────────────────────────────────────────────────────────────────

    public function getTypeSlug(): string
    {
        return 'square';
    }

    public function prepareCheckout(float $amount, string $currency, array $context = []): array
    {
        $appId      = $this->getApplicationId();
        $locationId = $this->getLocationId();

        if (empty($appId) || empty($locationId)) {
            $prefix = $this->sandbox ? 'SQUARE_SANDBOX_' : 'SQUARE_';
            return [
                'error' => "Square credentials ({$prefix}APPLICATION_ID and {$prefix}LOCATION_ID) are not configured.",
            ];
        }

        return [
            'processor'   => 'square',
            'appId'       => $appId,
            'locationId'  => $locationId,
            'environment' => $this->sandbox ? 'sandbox' : 'production',
            'currency'    => strtoupper(trim($currency ?: 'USD')),
            'amount'      => number_format($amount, 2, '.', ''),
        ];
    }

    public function buildChargePayload(string $gatewayToken, array $context = []): array
    {
        $token = trim($gatewayToken);
        $sourceId = $token;
        $verificationToken = null;

        // Allow passing "source_id|verification_token" if separated
        if (str_contains($token, '|')) {
            [$sourceId, $verificationToken] = explode('|', $token, 2);
        }

        $user = $context['user'] ?? null;

        return array_filter([
            'source_id'          => trim($sourceId),
            'verification_token' => $verificationToken ? trim($verificationToken) : null,
            'buyer_email'        => $user?->email ?? null,
        ], fn ($v) => $v !== null && $v !== '');
    }

    public function getCheckoutViewPath(): ?string
    {
        return __DIR__ . '/checkout.blade.php';
    }

    public function getCheckoutViewData(float $total, string $currency): array
    {
        $appId = '';
        $locationId = '';

        try {
            $appId = $this->getApplicationId();
            $locationId = $this->getLocationId(allowApiLookup: false);
        } catch (\Throwable) {
            // Handled gracefully in the view if unconfigured
        }

        return [
            'squareAppId'      => $appId,
            'squareLocationId' => $locationId,
            'squareSdkUrl'     => $this->getWebSdkUrl(),
            'squareIsSandbox'  => $this->sandbox,
        ];
    }

    public function matchesPayment(OrderPayment $payment): bool
    {
        $method   = strtolower(trim((string) $payment->payment_method));
        $response = strtolower(trim((string) $payment->processor_response));

        return str_contains($method, 'square') || str_contains($response, 'square');
    }

    /**
     * Handle incoming Square webhook notifications at POST /webhooks/square
     * or POST /webhooks/payment/square.
     *
     * Verifies `x-square-hmacsha256-signature` using Square's HMAC-SHA256 algorithm:
     * Base64(HMAC-SHA256(notificationUrl + rawBody, signatureKey)).
     */
    public function handleWebhook(Request $request): Response
    {
        $rawBody   = $request->getContent();
        $signature = (string) $request->header('x-square-hmacsha256-signature', '');
        $sigKey    = $this->getWebhookSignatureKey();

        if (!empty($sigKey)) {
            if (empty($signature) || !$this->verifyWebhookSignature($request->fullUrl(), $rawBody, $signature, $sigKey)) {
                Log::warning('[Square Webhook] Signature verification failed.');
                return response('Invalid signature.', 400);
            }
        }

        $payload   = json_decode($rawBody, true);
        $eventType = $payload['type'] ?? null;

        if (!is_array($payload) || empty($eventType)) {
            Log::warning('[Square Webhook] Invalid or empty webhook payload.');
            return response('Invalid payload.', 400);
        }

        Log::info('[Square Webhook] Received event: ' . $eventType, [
            'event_id' => $payload['event_id'] ?? null,
        ]);

        $object = $payload['data']['object'] ?? [];

        match ($eventType) {
            'payment.updated',
            'payment.created' => $this->handlePaymentWebhook($object['payment'] ?? []),
            'refund.updated',
            'refund.created'  => $this->handleRefundWebhook($object['refund'] ?? []),
            default           => Log::info('[Square Webhook] Unhandled event type: ' . $eventType),
        };

        return response('OK', 200);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Configuration & Helper Methods
    // ─────────────────────────────────────────────────────────────────────────

    public function getBaseUrl(): string
    {
        return $this->sandbox
            ? 'https://connect.squareupsandbox.com'
            : 'https://connect.squareup.com';
    }

    public function getWebSdkUrl(): string
    {
        return $this->sandbox
            ? 'https://sandbox.web.squarecdn.com/v1/square.js'
            : 'https://web.squarecdn.com/v1/square.js';
    }

    public function getApiVersion(): string
    {
        return (string) (config('services.square.api_version') ?: env('SQUARE_API_VERSION', '2025-01-23'));
    }

    public function getApplicationId(): string
    {
        $val = $this->sandbox
            ? (config('services.square.sandbox_application_id') ?: config('services.square.sandbox_app_id') ?: env('SQUARE_SANDBOX_APPLICATION_ID', ''))
            : (config('services.square.application_id') ?: config('services.square.app_id') ?: env('SQUARE_APPLICATION_ID', ''));

        return trim((string) $val);
    }

    public function getAccessToken(): string
    {
        $val = $this->sandbox
            ? (config('services.square.sandbox_access_token') ?: env('SQUARE_SANDBOX_ACCESS_TOKEN', ''))
            : (config('services.square.access_token') ?: env('SQUARE_ACCESS_TOKEN', ''));

        $token = trim((string) $val);

        if (empty($token)) {
            $varName = $this->sandbox ? 'SQUARE_SANDBOX_ACCESS_TOKEN' : 'SQUARE_ACCESS_TOKEN';
            throw new \RuntimeException("Square access token ({$varName}) is not set in .env.");
        }

        return $token;
    }

    /**
     * Return the Square Location ID from .env/config, or auto-resolve the primary
     * active location from GET /v2/locations if not explicitly configured.
     */
    public function getLocationId(bool $allowApiLookup = true): string
    {
        $val = $this->sandbox
            ? (config('services.square.sandbox_location_id') ?: env('SQUARE_SANDBOX_LOCATION_ID', ''))
            : (config('services.square.location_id') ?: env('SQUARE_LOCATION_ID', ''));

        $locationId = trim((string) $val);
        if (!empty($locationId) || !$allowApiLookup) {
            return $locationId;
        }

        $accessToken = $this->getAccessToken();
        $baseUrl     = $this->getBaseUrl();

        $response = Http::withToken($accessToken)
            ->withHeaders([
                'Square-Version' => $this->getApiVersion(),
                'Accept'         => 'application/json',
            ])
            ->get("{$baseUrl}/v2/locations");

        if ($response->successful()) {
            $locations = $response->json('locations') ?? [];
            foreach ($locations as $loc) {
                if (($loc['status'] ?? '') === 'ACTIVE' && !empty($loc['id'])) {
                    return (string) $loc['id'];
                }
            }
            if (!empty($locations[0]['id'])) {
                return (string) $locations[0]['id'];
            }
        }

        $varName = $this->sandbox ? 'SQUARE_SANDBOX_LOCATION_ID' : 'SQUARE_LOCATION_ID';
        throw new \RuntimeException("Square Location ID ({$varName}) is not set in .env and could not be resolved from /v2/locations.");
    }

    public function getWebhookSignatureKey(): string
    {
        $val = $this->sandbox
            ? (config('services.square.sandbox_webhook_signature_key') ?: env('SQUARE_SANDBOX_WEBHOOK_SIGNATURE_KEY', '') ?: config('services.square.webhook_signature_key') ?: env('SQUARE_WEBHOOK_SIGNATURE_KEY', ''))
            : (config('services.square.webhook_signature_key') ?: env('SQUARE_WEBHOOK_SIGNATURE_KEY', ''));

        return trim((string) $val);
    }

    /**
     * Verify Square webhook HMAC-SHA256 signature.
     */
    public function verifyWebhookSignature(string $notificationUrl, string $rawBody, string $signatureHeader, string $signatureKey): bool
    {
        $expected = base64_encode(hash_hmac('sha256', $notificationUrl . $rawBody, $signatureKey, true));
        return hash_equals($expected, $signatureHeader);
    }

    /**
     * Convert a major currency float amount to Square's integer smallest currency unit.
     */
    public function toSmallestCurrencyUnit(float $amount, string $currency = 'USD'): int
    {
        $curr = strtoupper(trim($currency ?: 'USD'));
        if (in_array($curr, self::ZERO_DECIMAL_CURRENCIES, true)) {
            return (int) round($amount);
        }
        return (int) round($amount * 100);
    }

    private function extractPaymentId(string $transactionId): string
    {
        $trimmed = trim($transactionId);
        if (preg_match('/Square Payment:\s*([a-zA-Z0-9_-]+)/i', $trimmed, $matches)) {
            return $matches[1];
        }
        return $trimmed;
    }

    private function extractSquareErrorMessage(?array $json, string $fallback): string
    {
        if (!is_array($json) || empty($json['errors']) || !is_array($json['errors'])) {
            return $fallback;
        }

        $messages = [];
        foreach ($json['errors'] as $err) {
            $detail = $err['detail'] ?? $err['code'] ?? null;
            if ($detail) {
                $messages[] = $detail;
            }
        }

        return !empty($messages) ? implode('; ', $messages) : $fallback;
    }

    private function handlePaymentWebhook(array $payment): void
    {
        $paymentId = $payment['id'] ?? null;
        $status    = strtoupper((string) ($payment['status'] ?? ''));

        if (empty($paymentId) || $status !== 'COMPLETED') {
            return;
        }

        $record = DB::table('order_payments')
            ->where('authorization_code', $paymentId)
            ->orWhere('processor_response', 'like', "%{$paymentId}%")
            ->first();

        if ($record) {
            $order = DB::table('orders')->where('id', $record->order_id)->first();
            if ($order && $order->order_status < 1) {
                DB::table('orders')
                    ->where('id', $record->order_id)
                    ->update(['order_status' => 1, 'updated_at' => now()]);
                Log::info("[Square Webhook] Order #{$record->order_id} confirmed via payment webhook.");
            }
        }
    }

    private function handleRefundWebhook(array $refund): void
    {
        $paymentId = $refund['payment_id'] ?? null;
        $status    = strtoupper((string) ($refund['status'] ?? ''));

        if (empty($paymentId) || $status !== 'COMPLETED') {
            return;
        }

        $record = DB::table('order_payments')
            ->where('authorization_code', $paymentId)
            ->orWhere('processor_response', 'like', "%{$paymentId}%")
            ->first();

        if ($record) {
            DB::table('order_payments')
                ->where('id', $record->id)
                ->update(['payment_status' => 2, 'updated_at' => now()]);

            DB::table('orders')
                ->where('id', $record->order_id)
                ->update(['order_status' => 3, 'updated_at' => now()]);
            Log::info("[Square Webhook] Order #{$record->order_id} marked as Refunded via Square webhook.");
        }
    }
}
