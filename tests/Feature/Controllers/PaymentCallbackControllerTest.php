<?php

namespace Plugins\Sirsoft\PayNhnkcp\Tests\Feature\Controllers;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderPaymentFactory;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Plugins\Sirsoft\PayNhnkcp\Tests\PluginTestCase;

class PaymentCallbackControllerTest extends PluginTestCase
{
    private const TEST_SITE_CD = 'T0000';

    private const TEST_SITE_KEY = 'TEST_SITE_KEY_0000';

    private function makeTransactionResponse(string $tno, string $ordrIdxx, int $amount, string $resCd = '0000'): array
    {
        return [
            'res_cd' => $resCd,
            'res_msg' => $resCd === '0000' ? '정상처리' : '승인실패',
            'tno' => $tno,
            'ordr_idxx' => $ordrIdxx,
            'good_mny' => $amount,
            'app_no' => 'APP12345',
            'card_no' => '4330****1234',
            'card_name' => '신한카드',
            'quota' => '00',
            'use_pay_method' => 'CARD',
            'app_time' => now()->format('YmdHis'),
        ];
    }

    private function createTestOrder(int $totalAmount = 50000): Order
    {
        $user = User::factory()->create();

        $order = OrderFactory::new()->create([
            'user_id' => $user->id,
            'order_number' => 'ORD-TEST-' . random_int(10000, 99999),
            'order_status' => OrderStatusEnum::PENDING_ORDER,
            'subtotal_amount' => $totalAmount,
            'total_discount_amount' => 0,
            'total_coupon_discount_amount' => 0,
            'total_product_coupon_discount_amount' => 0,
            'total_order_coupon_discount_amount' => 0,
            'total_code_discount_amount' => 0,
            'base_shipping_amount' => 0,
            'extra_shipping_amount' => 0,
            'shipping_discount_amount' => 0,
            'total_shipping_amount' => 0,
            'total_amount' => $totalAmount,
            'total_due_amount' => $totalAmount,
            'total_points_used_amount' => 0,
            'total_deposit_used_amount' => 0,
            'total_paid_amount' => 0,
        ]);

        OrderPaymentFactory::new()->create([
            'order_id' => $order->id,
            'payment_status' => PaymentStatusEnum::READY,
            'payment_method' => PaymentMethodEnum::CARD,
            'pg_provider' => 'nhnkcp',
            'paid_amount_local' => 0,
            'paid_at' => null,
            'transaction_id' => null,
            'card_approval_number' => null,
        ]);

        return $order;
    }

    private function mockPluginSettings(array $overrides = []): void
    {
        $defaults = [
            'is_test_mode' => true,
            'test_site_cd' => self::TEST_SITE_CD,
            'test_site_key' => self::TEST_SITE_KEY,
            'live_site_cd' => '',
            'live_site_key' => '',
            'redirect_success_url' => '/shop/orders/{orderId}/complete',
            'redirect_fail_url' => '/shop/checkout',
        ];

        $settingsMock = $this->createMock(\App\Services\PluginSettingsService::class);
        $settingsMock->method('get')
            ->willReturn(array_merge($defaults, $overrides));

        $this->app->instance(\App\Services\PluginSettingsService::class, $settingsMock);
    }

    private function makeCallbackParams(string $ordrIdxx, int $goodMny, array $overrides = []): array
    {
        return array_merge([
            'res_cd' => '0000',
            'res_msg' => '정상처리',
            'tno' => 'KCP_TNO_' . uniqid(),
            'ordr_idxx' => $ordrIdxx,
            'good_mny' => $goodMny,
            'enc_data' => base64_encode('encrypted_payment_data'),
            'enc_info' => base64_encode('encrypted_info'),
            'use_pay_method' => 'CARD',
        ], $overrides);
    }

    // ===== 성공 콜백 테스트 =====

    public function test_auth_callback_redirects_to_complete_page_on_valid_payment(): void
    {
        $order = $this->createTestOrder(50000);
        $this->mockPluginSettings();

        $tno = 'KCP_TNO_' . uniqid();
        $params = $this->makeCallbackParams($order->order_number, 50000, ['tno' => $tno]);

        Http::fake([
            'stgapi.kcp.co.kr/*' => Http::response(
                $this->makeTransactionResponse($tno, $order->order_number, 50000),
                200
            ),
        ]);

        $response = $this->post('/plugins/sirsoft-pay_nhnkcp/payment/callback', $params);

        $response->assertRedirect("/shop/orders/{$order->order_number}/complete");

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::PAYMENT_COMPLETE, $order->order_status);

        $payment = $order->payment;
        $payment->refresh();
        $this->assertEquals($tno, $payment->transaction_id);
        $this->assertEquals('APP12345', $payment->card_approval_number);
    }

    public function test_auth_callback_redirects_to_fail_on_res_cd_not_0000(): void
    {
        $this->mockPluginSettings();

        $params = $this->makeCallbackParams('ORD-TEST-99999', 50000, [
            'res_cd' => '8001',
            'res_msg' => '사용자 취소',
        ]);

        $response = $this->post('/plugins/sirsoft-pay_nhnkcp/payment/callback', $params);

        $response->assertRedirect();
        $this->assertStringContainsString('error=8001', $response->headers->get('Location'));
    }

    public function test_auth_callback_redirects_to_fail_on_order_not_found(): void
    {
        $this->mockPluginSettings();

        $tno = 'KCP_TNO_NOTFOUND';
        $params = $this->makeCallbackParams('NON_EXISTENT_ORDER', 50000, ['tno' => $tno]);

        Http::fake([
            'stgapi.kcp.co.kr/*' => Http::response(
                $this->makeTransactionResponse($tno, 'NON_EXISTENT_ORDER', 50000),
                200
            ),
        ]);

        $response = $this->post('/plugins/sirsoft-pay_nhnkcp/payment/callback', $params);

        $response->assertRedirect();
        $this->assertStringContainsString('error=order_not_found', $response->headers->get('Location'));
    }

    public function test_auth_callback_redirects_to_fail_when_transaction_confirm_fails(): void
    {
        $order = $this->createTestOrder(50000);
        $this->mockPluginSettings();

        $tno = 'KCP_TNO_' . uniqid();
        $params = $this->makeCallbackParams($order->order_number, 50000, ['tno' => $tno]);

        Http::fake([
            'stgapi.kcp.co.kr/*' => Http::response(
                $this->makeTransactionResponse($tno, $order->order_number, 50000, '9999'),
                200
            ),
        ]);

        $response = $this->post('/plugins/sirsoft-pay_nhnkcp/payment/callback', $params);

        $response->assertRedirect();
        $this->assertStringContainsString('error=9999', $response->headers->get('Location'));
    }

    public function test_auth_callback_redirects_to_fail_on_transaction_api_error(): void
    {
        $order = $this->createTestOrder(50000);
        $this->mockPluginSettings();

        $params = $this->makeCallbackParams($order->order_number, 50000);

        Http::fake([
            'stgapi.kcp.co.kr/*' => Http::response(null, 500),
        ]);

        $response = $this->post('/plugins/sirsoft-pay_nhnkcp/payment/callback', $params);

        $response->assertRedirect();
        $this->assertStringContainsString('error=confirm_failed', $response->headers->get('Location'));
    }

    public function test_auth_callback_redirects_to_fail_url_on_missing_params(): void
    {
        $this->mockPluginSettings();

        $response = $this->post('/plugins/sirsoft-pay_nhnkcp/payment/callback', [
            'res_cd' => '0000',
        ]);

        $response->assertRedirect();
        $this->assertStringContainsString('error=invalid_params', $response->headers->get('Location'));
    }

    public function test_auth_callback_redirects_to_custom_success_url(): void
    {
        $order = $this->createTestOrder(50000);
        $this->mockPluginSettings(['redirect_success_url' => '/custom/payment/{orderId}/done']);

        $tno = 'KCP_TNO_CUSTOM';
        $params = $this->makeCallbackParams($order->order_number, 50000, ['tno' => $tno]);

        Http::fake([
            'stgapi.kcp.co.kr/*' => Http::response(
                $this->makeTransactionResponse($tno, $order->order_number, 50000),
                200
            ),
        ]);

        $response = $this->post('/plugins/sirsoft-pay_nhnkcp/payment/callback', $params);

        $response->assertRedirect("/custom/payment/{$order->order_number}/done");
    }

    public function test_auth_callback_detects_mobile_device(): void
    {
        $order = $this->createTestOrder(50000);
        $this->mockPluginSettings();

        $tno = 'KCP_TNO_MOBILE';
        $params = $this->makeCallbackParams($order->order_number, 50000, ['tno' => $tno]);

        Http::fake([
            'stgapi.kcp.co.kr/*' => Http::response(
                $this->makeTransactionResponse($tno, $order->order_number, 50000),
                200
            ),
        ]);

        $response = $this->post(
            '/plugins/sirsoft-pay_nhnkcp/payment/callback',
            $params,
            ['User-Agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X)']
        );

        $response->assertRedirect("/shop/orders/{$order->order_number}/complete");

        $payment = $order->payment;
        $payment->refresh();
        $this->assertEquals('mobile', $payment->payment_device);
    }

    // ===== 가상계좌 입금 통보 테스트 =====

    public function test_vbank_notify_returns_ok_on_successful_deposit(): void
    {
        $order = $this->createTestOrder(30000);

        $response = $this->post('/plugins/sirsoft-pay_nhnkcp/payment/vbank-notify', [
            'tno' => 'KCP_VBANK_TNO_001',
            'ordr_idxx' => $order->order_number,
            'good_mny' => 30000,
            'res_cd' => '0000',
            'res_msg' => '입금완료',
            'bank_name' => '국민은행',
            'account' => '1234567890',
            'account_holder' => '홍길동',
            'vnbank_expire_date' => now()->addDays(3)->format('Ymd'),
        ]);

        $response->assertOk();
        $this->assertEquals('OK', $response->getContent());

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::PAYMENT_COMPLETE, $order->order_status);
    }

    public function test_vbank_notify_returns_ok_on_non_0000_res_cd(): void
    {
        $response = $this->post('/plugins/sirsoft-pay_nhnkcp/payment/vbank-notify', [
            'tno' => 'KCP_VBANK_TNO_002',
            'ordr_idxx' => 'ORD-TEST-CANCEL',
            'good_mny' => 30000,
            'res_cd' => '8001',
            'res_msg' => '입금취소',
        ]);

        $response->assertOk();
        $this->assertEquals('OK', $response->getContent());
    }

    public function test_vbank_notify_returns_fail_on_order_not_found(): void
    {
        $response = $this->post('/plugins/sirsoft-pay_nhnkcp/payment/vbank-notify', [
            'tno' => 'KCP_VBANK_TNO_003',
            'ordr_idxx' => 'NON_EXISTENT_ORDER',
            'good_mny' => 30000,
            'res_cd' => '0000',
        ]);

        $response->assertOk();
        $this->assertEquals('FAIL', $response->getContent());
    }
}
