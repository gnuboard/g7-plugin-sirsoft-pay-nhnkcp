<?php

namespace Plugins\Sirsoft\PayNhnkcp\Tests\Feature\Controllers;

use Mockery;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderAddress;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Modules\Sirsoft\Ecommerce\Services\OrderProcessingService;
use Plugins\Sirsoft\PayNhnkcp\Tests\PluginTestCase;

class PaymentCloseReportControllerTest extends PluginTestCase
{
    public function test_close_report_marks_pending_order_failed_and_payment_cancelled(): void
    {
        $order = $this->makeOrder('ORD-KCP-CLOSE-001', 10000);
        $order->setRelation('shippingAddress', new OrderAddress([
            'address_type' => 'shipping',
            'orderer_email' => 'buyer@example.com',
            'orderer_phone' => '010-1234-5678',
        ]));

        $orderService = Mockery::mock(OrderProcessingService::class);
        $orderService->shouldReceive('findByOrderNumber')
            ->once()
            ->with('ORD-KCP-CLOSE-001')
            ->andReturn($order);
        $orderService->shouldReceive('failPayment')
            ->once()
            ->with($order, 'USER_CANCEL', '사용자가 NHN KCP 결제창을 닫았습니다.')
            ->andReturn($order);
        $orderService->shouldReceive('recordPaymentCancellation')
            ->once()
            ->with($order, 'USER_CANCEL', 'kcp-window-closed')
            ->andReturn($order);

        $this->app->instance(OrderProcessingService::class, $orderService);

        $response = $this->postJson('/api/plugins/sirsoft-pay_nhnkcp/payment/close-report', [
            'oid' => 'ORD-KCP-CLOSE-001',
            'price' => 10000,
            'buyer_email' => 'BUYER@example.com',
            'buyer_phone' => '01012345678',
            'payment_method' => 'card',
            'reason' => 'kcp-window-closed',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'recorded');
    }

    public function test_close_report_rejects_amount_mismatch(): void
    {
        $order = $this->makeOrder('ORD-KCP-CLOSE-002', 10000);

        $orderService = Mockery::mock(OrderProcessingService::class);
        $orderService->shouldReceive('findByOrderNumber')
            ->once()
            ->with('ORD-KCP-CLOSE-002')
            ->andReturn($order);
        $orderService->shouldNotReceive('failPayment');
        $orderService->shouldNotReceive('recordPaymentCancellation');

        $this->app->instance(OrderProcessingService::class, $orderService);

        $response = $this->postJson('/api/plugins/sirsoft-pay_nhnkcp/payment/close-report', [
            'oid' => 'ORD-KCP-CLOSE-002',
            'price' => 9000,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('errors.message.0', 'Payment amount does not match the order amount.');
    }

    public function test_close_report_rejects_invalid_payment_currency_without_server_error(): void
    {
        $order = $this->makeOrder('ORD-KCP-CLOSE-CURRENCY-001', 10000);
        $order->currency = 'USD';
        $order->currency_snapshot = self::invalidUsdCurrencySnapshot();

        $orderService = Mockery::mock(OrderProcessingService::class);
        $orderService->shouldReceive('findByOrderNumber')
            ->once()
            ->with('ORD-KCP-CLOSE-CURRENCY-001')
            ->andReturn($order);
        $orderService->shouldNotReceive('failPayment');
        $orderService->shouldNotReceive('recordPaymentCancellation');

        $this->app->instance(OrderProcessingService::class, $orderService);

        $response = $this->postJson('/api/plugins/sirsoft-pay_nhnkcp/payment/close-report', [
            'oid' => 'ORD-KCP-CLOSE-CURRENCY-001',
            'price' => 10000,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('errors.message.0', 'Payment currency is not chargeable.');
    }

    public function test_close_report_rejects_buyer_mismatch(): void
    {
        $order = $this->makeOrder('ORD-KCP-CLOSE-003', 10000);
        $order->setRelation('shippingAddress', new OrderAddress([
            'address_type' => 'shipping',
            'orderer_email' => 'buyer@example.com',
            'orderer_phone' => '010-1234-5678',
        ]));

        $orderService = Mockery::mock(OrderProcessingService::class);
        $orderService->shouldReceive('findByOrderNumber')
            ->once()
            ->with('ORD-KCP-CLOSE-003')
            ->andReturn($order);
        $orderService->shouldNotReceive('failPayment');
        $orderService->shouldNotReceive('recordPaymentCancellation');

        $this->app->instance(OrderProcessingService::class, $orderService);

        $response = $this->postJson('/api/plugins/sirsoft-pay_nhnkcp/payment/close-report', [
            'oid' => 'ORD-KCP-CLOSE-003',
            'price' => 10000,
            'buyer_email' => 'attacker@example.com',
            'buyer_phone' => '01012345678',
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('errors.message.0', 'Order buyer verification failed.');
    }

    public function test_close_report_ignores_order_that_is_no_longer_payable(): void
    {
        $order = $this->makeOrder('ORD-KCP-CLOSE-004', 10000);
        $order->order_status = OrderStatusEnum::PAYMENT_COMPLETE;

        $orderService = Mockery::mock(OrderProcessingService::class);
        $orderService->shouldReceive('findByOrderNumber')
            ->once()
            ->with('ORD-KCP-CLOSE-004')
            ->andReturn($order);
        $orderService->shouldNotReceive('failPayment');
        $orderService->shouldNotReceive('recordPaymentCancellation');

        $this->app->instance(OrderProcessingService::class, $orderService);

        $response = $this->postJson('/api/plugins/sirsoft-pay_nhnkcp/payment/close-report', [
            'oid' => 'ORD-KCP-CLOSE-004',
            'price' => 10000,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'ignored')
            ->assertJsonPath('data.reason', 'order_not_payable');
    }

    public function test_close_report_ignores_order_when_payment_already_paid(): void
    {
        // race 재현: 승인 콜백이 payment 를 먼저 PAID 로 갱신했으나 order_status 는 아직 PENDING_ORDER.
        $order = $this->makeOrder('ORD-KCP-CLOSE-PAID-001', 10000);
        $order->setRelation('payment', new OrderPayment([
            'payment_status' => PaymentStatusEnum::PAID,
        ]));

        $orderService = Mockery::mock(OrderProcessingService::class);
        $orderService->shouldReceive('findByOrderNumber')
            ->once()
            ->with('ORD-KCP-CLOSE-PAID-001')
            ->andReturn($order);
        $orderService->shouldNotReceive('failPayment');
        $orderService->shouldNotReceive('recordPaymentCancellation');

        $this->app->instance(OrderProcessingService::class, $orderService);

        $response = $this->postJson('/api/plugins/sirsoft-pay_nhnkcp/payment/close-report', [
            'oid' => 'ORD-KCP-CLOSE-PAID-001',
            'price' => 10000,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'ignored')
            ->assertJsonPath('data.reason', 'payment_already_paid');
    }

    private function makeOrder(string $orderNumber, int $amount): Order
    {
        $order = new Order;
        $order->order_number = $orderNumber;
        $order->order_status = OrderStatusEnum::PENDING_ORDER;
        $order->currency = 'KRW';
        $order->currency_snapshot = self::krwCurrencySnapshot();
        $order->total_due_amount = $amount;

        return $order;
    }

    private static function krwCurrencySnapshot(): array
    {
        return [
            'base_currency' => 'KRW',
            'order_currency' => 'KRW',
            'base_unit' => 1,
            'exchange_rates' => [
                'KRW' => [
                    'rate' => 1,
                    'rounding_unit' => '1',
                    'rounding_method' => 'round',
                    'decimal_places' => 0,
                    'base_unit' => 1,
                ],
            ],
        ];
    }

    private static function invalidUsdCurrencySnapshot(): array
    {
        return [
            'base_currency' => 'KRW',
            'order_currency' => 'USD',
            'base_unit' => 1000,
            'exchange_rates' => [
                'KRW' => [
                    'rate' => 1,
                    'rounding_unit' => '1',
                    'rounding_method' => 'round',
                    'decimal_places' => 0,
                    'base_unit' => 1000,
                ],
                'USD' => [
                    'rate' => 0,
                    'rounding_unit' => '0.01',
                    'rounding_method' => 'round',
                    'decimal_places' => 2,
                    'base_unit' => 1,
                ],
            ],
        ];
    }
}
