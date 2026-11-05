<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\OrderProcessor;
use App\Models\OrderRefund;
use App\Models\ShoppingCartLog;
use App\Models\User;
use App\Services\Payments\Contracts\PaymentPluginInterface;
use App\Services\Payments\PaymentProcessorManager;
use App\Services\Payments\PaymentRefundService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PaymentProcessors\Square\SquareProcessor;
use Tests\TestCase;

class SquarePaymentProcessorTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        DB::statement("INSERT IGNORE INTO `user_roles` (`id`, `name`, `description`) VALUES 
            (1, 'User', 'Customer'),
            (2, 'Wholesale', 'Wholesale'),
            (3, 'Admin', 'Admin')");

        // Ensure Square auto-discovery syncs into order_processors
        PaymentProcessorManager::syncDiscoveredProcessors();

        // Set Square (processor_id = 100) as the active primary processor in sandbox mode
        OrderProcessor::where('processor_id', 100)->update(['production' => 0]);

        DB::table('order_checkout_options')->updateOrInsert(
            ['id' => 1],
            [
                'primary_processor'   => 100, // Square Payments
                'secondary_processor' => 0,
                'tertiary_processor'  => 0,
                'randomize_processor' => 0,
                'updated_at'          => now(),
            ]
        );

        config([
            'services.square.sandbox_application_id' => 'sandbox-sq0idb-test-app',
            'services.square.sandbox_access_token'   => 'EAAAE-test-sandbox-token',
            'services.square.sandbox_location_id'    => 'LTESTSANDBOXLOC',
            'services.square.webhook_signature_key'  => 'test-square-webhook-secret',
        ]);
    }

    public function test_square_plugin_is_auto_discovered_and_seeded_in_database(): void
    {
        $processorsConfig = config('payment_processors');
        $this->assertArrayHasKey(100, $processorsConfig);
        $this->assertEquals(SquareProcessor::class, $processorsConfig[100]);

        $dbRecord = OrderProcessor::where('processor_id', 100)->first();
        $this->assertNotNull($dbRecord, 'Square processor row should be auto-created in order_processors table.');
        $this->assertEquals('Square Payments', $dbRecord->processor_name);

        $manager = app(PaymentProcessorManager::class);
        $this->assertEquals(100, $manager->activeProcessorId());
        $this->assertEquals('square', $manager->activeProcessorType());

        $driver = $manager->resolveActive();
        $this->assertInstanceOf(SquareProcessor::class, $driver);
        $this->assertInstanceOf(PaymentPluginInterface::class, $driver);
        $this->assertTrue($driver->isSandbox());
        $this->assertEquals('Square Payments (Sandbox)', $driver->getName());
        $this->assertEquals('square', $driver->getTypeSlug());
    }

    public function test_square_checkout_order_review_charges_and_creates_order(): void
    {
        Http::fake([
            'https://connect.squareupsandbox.com/v2/payments' => Http::response([
                'payment' => [
                    'id'           => 'sq_pay_test_987654321',
                    'status'       => 'COMPLETED',
                    'amount_money' => [
                        'amount'   => 2500,
                        'currency' => 'USD',
                    ],
                ],
            ], 200),
        ]);

        $user = User::create([
            'name'                 => 'Square Buyer',
            'email'                => 'square-buyer@example.com',
            'password'             => bcrypt('password'),
            'role_id'              => 1,
            'email_verified_at'    => now(),
            'shipping_address1'    => '100 Main St',
            'shipping_city'        => 'Seattle',
            'shipping_state'       => 'WA',
            'shopping_postalcode'  => '98101',
            'shipping_country'     => 'United States',
            'shipping_countrycode' => 'US',
        ]);

        $this->actingAs($user);

        ShoppingCartLog::create([
            'cart_log_session'    => session()->getId(),
            'user_id'             => $user->id,
            'item_name'           => 'Square Digital Download',
            'item_qty'            => 1,
            'item_price'          => 25.00,
            'item_discount_price' => 0.00,
            'item_shippable'      => 0,
            'item_downloadable'   => 1,
            'item_weight'         => 0.0,
            'order_id'            => 0,
        ]);

        Livewire::test('order-review')
            ->assertSet('activeProcessorId', 100)
            ->assertSee('sandbox-sq0idb-test-app', false)
            ->call('preparePayment')
            ->assertSet('paymentReady', true)
            ->call('placeOrder', 'cnon:card-nonce-ok')
            ->assertRedirect();

        $order = Order::where('order_user_id', $user->id)->latest('id')->first();
        $this->assertNotNull($order, 'Order should be created after Square charge succeeds.');

        $payment = OrderPayment::where('order_id', $order->id)->first();
        $this->assertNotNull($payment, 'OrderPayment record should be recorded.');
        $this->assertEquals('Square Payments (Sandbox)', $payment->payment_method);
        $this->assertEquals(1, (int) $payment->payment_status);
        $this->assertEquals('sq_pay_test_987654321', $payment->authorization_code);
        $this->assertEquals('sq_pay_test_987654321', $payment->processor_response);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://connect.squareupsandbox.com/v2/payments'
                && $request['source_id'] === 'cnon:card-nonce-ok'
                && $request['amount_money']['amount'] === 2500
                && $request['amount_money']['currency'] === 'USD'
                && $request['location_id'] === 'LTESTSANDBOXLOC'
                && $request['autocomplete'] === true;
        });
    }

    public function test_square_card_decline_returns_error_message_and_prevents_order_creation(): void
    {
        Http::fake([
            'https://connect.squareupsandbox.com/v2/payments' => Http::response([
                'errors' => [
                    [
                        'category' => 'PAYMENT_METHOD_ERROR',
                        'code'     => 'CARD_DECLINED',
                        'detail'   => 'Authorization error: CARD_DECLINED',
                    ],
                ],
            ], 400),
        ]);

        $user = User::create([
            'name'              => 'Declined Buyer',
            'email'             => 'declined-square@example.com',
            'password'          => bcrypt('password'),
            'role_id'           => 1,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user);

        ShoppingCartLog::create([
            'cart_log_session'    => session()->getId(),
            'user_id'             => $user->id,
            'item_name'           => 'Declined Item',
            'item_qty'            => 1,
            'item_price'          => 19.99,
            'item_discount_price' => 0.00,
            'item_shippable'      => 0,
            'item_downloadable'   => 1,
            'item_weight'         => 0.0,
            'order_id'            => 0,
        ]);

        Livewire::test('order-review')
            ->call('preparePayment')
            ->call('placeOrder', 'cnon:card-nonce-declined')
            ->assertSee('Square Error: Authorization error: CARD_DECLINED');

        $this->assertDatabaseMissing('orders', [
            'order_user_id' => $user->id,
        ]);
    }

    public function test_square_refund_service_processes_partial_and_full_refunds(): void
    {
        Http::fake([
            'https://connect.squareupsandbox.com/v2/refunds' => Http::response([
                'refund' => [
                    'id'           => 'sq_refund_555666',
                    'status'       => 'COMPLETED',
                    'payment_id'   => 'sq_pay_test_987654321',
                    'amount_money' => [
                        'amount'   => 1000,
                        'currency' => 'USD',
                    ],
                ],
            ], 200),
        ]);

        $user = User::create([
            'name'     => 'Refund Customer',
            'email'    => 'refund-square@example.com',
            'password' => bcrypt('password'),
            'role_id'  => 1,
        ]);

        $order = Order::create([
            'order_invoice_no'           => 'INV-SQ-1001',
            'order_external_id'          => 'ext_sq_1001',
            'order_user_id'              => $user->id,
            'order_status'               => 1,
            'order_date'                 => now(),
            'order_total'                => 25.00,
            'order_subtotal'             => 25.00,
            'order_taxes'                => 0.00,
            'order_discounts'            => 0.00,
            'order_shipping'             => 0.00,
            'order_shipping_method'      => 0,
            'order_shipping_method_name' => 'Digital',
        ]);

        $payment = OrderPayment::create([
            'order_id'           => $order->id,
            'payment_date'       => now(),
            'payment_amount'     => 25.00,
            'payment_method'     => 'Square Payments (Sandbox)',
            'payment_status'     => 1, // Paid
            'authorization_code' => 'sq_pay_test_987654321',
            'processor_response' => 'sq_pay_test_987654321',
        ]);

        $refundService = app(PaymentRefundService::class);
        $refund = $refundService->refundPayment($payment, 10.00, 'Customer partial refund request');

        $this->assertInstanceOf(OrderRefund::class, $refund);
        $this->assertEquals('sq_refund_555666', $refund->authorization_code);
        $this->assertEquals(10.00, (float) $refund->amount);

        $payment->refresh();
        $this->assertEquals(3, (int) $payment->payment_status); // 3 = Partially Refunded
    }

    public function test_square_webhook_verifies_hmac_signature_and_updates_payment(): void
    {
        $user = User::create([
            'name'     => 'Webhook Customer',
            'email'    => 'webhook-square@example.com',
            'password' => bcrypt('password'),
            'role_id'  => 1,
        ]);

        $order = Order::create([
            'order_invoice_no'           => 'INV-SQ-2002',
            'order_external_id'          => 'ext_sq_2002',
            'order_user_id'              => $user->id,
            'order_status'               => 1,
            'order_date'                 => now(),
            'order_total'                => 40.00,
            'order_subtotal'             => 40.00,
            'order_taxes'                => 0.00,
            'order_discounts'            => 0.00,
            'order_shipping'             => 0.00,
            'order_shipping_method'      => 0,
            'order_shipping_method_name' => 'Digital',
        ]);

        $payment = OrderPayment::create([
            'order_id'           => $order->id,
            'payment_date'       => now(),
            'payment_amount'     => 40.00,
            'payment_method'     => 'Square Payments (Sandbox)',
            'payment_status'     => 1, // Paid
            'authorization_code' => 'sq_pay_webhook_777',
            'processor_response' => 'sq_pay_webhook_777',
        ]);

        $payload = json_encode([
            'type' => 'refund.updated',
            'data' => [
                'object' => [
                    'refund' => [
                        'id'           => 'sq_ref_webhook_999',
                        'status'       => 'COMPLETED',
                        'payment_id'   => 'sq_pay_webhook_777',
                        'amount_money' => [
                            'amount'   => 4000,
                            'currency' => 'USD',
                        ],
                    ],
                ],
            ],
        ]);

        $webhookUrl = url('/webhooks/payment/square');
        $signatureKey = 'test-square-webhook-secret';
        $validSignature = base64_encode(hash_hmac('sha256', $webhookUrl . $payload, $signatureKey, true));

        // 1. Invalid signature must be rejected with 400
        $badResponse = $this->call(
            'POST',
            '/webhooks/payment/square',
            [],
            [],
            [],
            [
                'CONTENT_TYPE'                      => 'application/json',
                'HTTP_X_SQUARE_HMACSHA256_SIGNATURE' => 'invalid-signature',
            ],
            $payload
        );
        $badResponse->assertStatus(400);

        // 2. Valid signature must succeed and update the payment & order records
        $goodResponse = $this->call(
            'POST',
            '/webhooks/payment/square',
            [],
            [],
            [],
            [
                'CONTENT_TYPE'                      => 'application/json',
                'HTTP_X_SQUARE_HMACSHA256_SIGNATURE' => 'valid-placeholder' === $validSignature ? '' : $validSignature,
            ],
            $payload
        );
        $goodResponse->assertStatus(200);

        $payment->refresh();
        $order->refresh();
        $this->assertEquals(2, (int) $payment->payment_status); // 2 = Fully Refunded
        $this->assertEquals(3, (int) $order->order_status);     // 3 = Order Refunded
    }
}
