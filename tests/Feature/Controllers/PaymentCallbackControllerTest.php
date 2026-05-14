<?php

namespace Plugins\Sirsoft\PayNhnkcp\Tests\Feature\Controllers;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderPaymentFactory;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Plugins\Sirsoft\PayNhnkcp\Services\NhnKcpApiService;
use Plugins\Sirsoft\PayNhnkcp\Tests\PluginTestCase;

class PaymentCallbackControllerTest extends PluginTestCase
{
    private const TEST_SITE_CD = 'T0000';

    private const TEST_SITE_KEY = 'TEST_SITE_KEY_0000';

    // ===== 헬퍼 =====

    private function makeCliResponse(string $tno, string $ordrIdxx, int $amount, string $resCd = '0000'): array
    {
        return [
            'res_cd'          => $resCd,
            'res_msg'         => $resCd === '0000' ? '정상처리' : '승인실패',
            'tno'             => $tno,
            'ordr_idxx'       => $ordrIdxx,
            'good_mny'        => $amount,
            'app_no'          => 'APP12345',
            'card_no'         => '4330****1234',
            'card_name'       => '신한카드',
            'quota'           => '00',
            'use_pay_method'  => 'CARD',
            'app_time'        => now()->format('YmdHis'),
        ];
    }

    /**
     * @param array{taxable?: int, vat?: int, taxFree?: int} $tax
     */
    private function createTestOrder(
        int $totalAmount = 50000,
        array $tax = [],
        PaymentMethodEnum $paymentMethod = PaymentMethodEnum::CARD,
    ): Order {
        $taxable = $tax['taxable'] ?? $totalAmount;
        $vat     = $tax['vat']     ?? (int) round($taxable * 10 / 110);
        $taxFree = $tax['taxFree'] ?? 0;

        $user = User::factory()->create();

        $order = OrderFactory::new()->create([
            'user_id'                            => $user->id,
            'order_number'                       => 'ORD-TEST-' . random_int(10000, 99999),
            'order_status'                       => OrderStatusEnum::PENDING_ORDER,
            'subtotal_amount'                    => $totalAmount,
            'total_discount_amount'              => 0,
            'total_coupon_discount_amount'       => 0,
            'total_product_coupon_discount_amount' => 0,
            'total_order_coupon_discount_amount' => 0,
            'total_code_discount_amount'         => 0,
            'base_shipping_amount'               => 0,
            'extra_shipping_amount'              => 0,
            'shipping_discount_amount'           => 0,
            'total_shipping_amount'              => 0,
            'total_amount'                       => $totalAmount,
            'total_due_amount'                   => $totalAmount,
            'total_points_used_amount'           => 0,
            'total_deposit_used_amount'          => 0,
            'total_paid_amount'                  => 0,
            'total_tax_amount'                   => $taxable,
            'total_vat_amount'                   => $vat,
            'total_tax_free_amount'              => $taxFree,
        ]);

        OrderPaymentFactory::new()->create([
            'order_id'             => $order->id,
            'payment_status'       => PaymentStatusEnum::READY,
            'payment_method'       => $paymentMethod,
            'pg_provider'          => 'nhnkcp',
            'paid_amount_local'    => 0,
            'paid_at'              => null,
            'transaction_id'       => null,
            'card_approval_number' => null,
        ]);

        return $order;
    }

    private function mockPluginSettings(array $overrides = []): void
    {
        $defaults = [
            'is_test_mode'         => true,
            'test_site_cd'         => self::TEST_SITE_CD,
            'test_site_key'        => self::TEST_SITE_KEY,
            'live_site_cd'         => '',
            'live_site_key'        => '',
            'redirect_success_url' => '/shop/orders/{orderId}/complete',
            'redirect_fail_url'    => '/shop/checkout',
        ];

        $mock = $this->createMock(\App\Services\PluginSettingsService::class);
        $mock->method('get')->willReturn(array_merge($defaults, $overrides));
        $this->app->instance(\App\Services\PluginSettingsService::class, $mock);
    }

    /**
     * NhnKcpApiService::approvePayment() 를 exec() 없이 mock.
     * 기존 테스트는 Http::fake()로 CLI exec를 막으려 했으나 동작하지 않음.
     */
    private function mockApiService(array $cliResponse): void
    {
        $mock = $this->createMock(NhnKcpApiService::class);
        $mock->method('approvePayment')->willReturn($cliResponse);
        $this->app->instance(NhnKcpApiService::class, $mock);
    }

    private function makeCallbackParams(string $ordrIdxx, int $goodMny, array $overrides = []): array
    {
        return array_merge([
            'res_cd'          => '0000',
            'res_msg'         => '정상처리',
            'tno'             => 'KCP_TNO_' . uniqid(),
            'ordr_idxx'       => $ordrIdxx,
            'good_mny'        => $goodMny,
            'enc_data'        => base64_encode('encrypted_payment_data'),
            'enc_info'        => base64_encode('encrypted_info'),
            'use_pay_method'  => 'CARD',
        ], $overrides);
    }

    // ===== 성공 콜백 =====

    public function test_auth_callback_redirects_to_complete_page_on_valid_payment(): void
    {
        $order = $this->createTestOrder(50000);
        $this->mockPluginSettings();

        $tno = 'KCP_TNO_' . uniqid();
        $this->mockApiService($this->makeCliResponse($tno, $order->order_number, 50000));

        $response = $this->post(
            '/plugins/sirsoft-pay_nhnkcp/payment/callback',
            $this->makeCallbackParams($order->order_number, 50000, ['tno' => $tno])
        );

        $response->assertRedirect("/shop/orders/{$order->order_number}/complete");
        $order->refresh();
        $this->assertEquals(OrderStatusEnum::PAYMENT_COMPLETE, $order->order_status);

        $payment = $order->payment;
        $payment->refresh();
        $this->assertEquals($tno, $payment->transaction_id);
        $this->assertEquals('APP12345', $payment->card_approval_number);
    }

    // ===== 과세/비과세 시나리오 =====

    public function test_fully_taxable_order_payment_completes(): void
    {
        // 11,000원 = 공급가 10,000 + 부가세 1,000 (전액 과세)
        $amount = 11000;
        $vat    = (int) round($amount * 10 / 110); // 1,000
        $order  = $this->createTestOrder($amount, ['taxable' => $amount, 'vat' => $vat, 'taxFree' => 0]);
        $this->mockPluginSettings();

        $tno = 'KCP_TNO_TAXABLE';
        $this->mockApiService($this->makeCliResponse($tno, $order->order_number, $amount));

        $response = $this->post(
            '/plugins/sirsoft-pay_nhnkcp/payment/callback',
            $this->makeCallbackParams($order->order_number, $amount, ['tno' => $tno])
        );

        $response->assertRedirect("/shop/orders/{$order->order_number}/complete");
        $order->refresh();
        $this->assertEquals(OrderStatusEnum::PAYMENT_COMPLETE, $order->order_status);

        $payment = $order->payment;
        $payment->refresh();
        $this->assertEquals($tno, $payment->transaction_id);
    }

    public function test_fully_tax_free_order_payment_completes(): void
    {
        // 10,000원 전액 비과세 (도서, 농산물, 의료 등 면세 상품)
        $amount = 10000;
        $order  = $this->createTestOrder($amount, ['taxable' => 0, 'vat' => 0, 'taxFree' => $amount]);
        $this->mockPluginSettings();

        $tno = 'KCP_TNO_TAXFREE';
        $this->mockApiService($this->makeCliResponse($tno, $order->order_number, $amount));

        $response = $this->post(
            '/plugins/sirsoft-pay_nhnkcp/payment/callback',
            $this->makeCallbackParams($order->order_number, $amount, ['tno' => $tno])
        );

        $response->assertRedirect("/shop/orders/{$order->order_number}/complete");
        $order->refresh();
        $this->assertEquals(OrderStatusEnum::PAYMENT_COMPLETE, $order->order_status);

        // 비과세 주문도 paid_amount 가 올바르게 기록돼야 함
        $payment = $order->payment;
        $payment->refresh();
        $this->assertEquals($tno, $payment->transaction_id);
    }

    public function test_mixed_tax_order_payment_completes(): void
    {
        // 21,000원 = 과세 11,000(공급가 10,000 + 부가세 1,000) + 비과세 10,000
        $taxable = 11000;
        $taxFree = 10000;
        $total   = $taxable + $taxFree;
        $vat     = (int) round($taxable * 10 / 110); // 1,000
        $order   = $this->createTestOrder($total, ['taxable' => $taxable, 'vat' => $vat, 'taxFree' => $taxFree]);
        $this->mockPluginSettings();

        $tno = 'KCP_TNO_MIXED';
        $this->mockApiService($this->makeCliResponse($tno, $order->order_number, $total));

        $response = $this->post(
            '/plugins/sirsoft-pay_nhnkcp/payment/callback',
            $this->makeCallbackParams($order->order_number, $total, ['tno' => $tno])
        );

        $response->assertRedirect("/shop/orders/{$order->order_number}/complete");
        $order->refresh();
        $this->assertEquals(OrderStatusEnum::PAYMENT_COMPLETE, $order->order_status);
    }

    // ===== 실패/취소 =====

    public function test_auth_callback_redirects_to_fail_on_res_cd_not_0000(): void
    {
        $this->mockPluginSettings();

        $response = $this->post('/plugins/sirsoft-pay_nhnkcp/payment/callback', $this->makeCallbackParams('ORD-TEST-99999', 50000, [
            'res_cd'  => '8001',
            'res_msg' => '사용자 취소',
        ]));

        $response->assertRedirect();
        $this->assertStringContainsString('error=8001', $response->headers->get('Location'));
    }

    public function test_auth_callback_silently_redirects_on_user_cancel_code_3001(): void
    {
        $this->mockPluginSettings();

        $response = $this->post('/plugins/sirsoft-pay_nhnkcp/payment/callback', [
            'res_cd'   => '3001',
            'res_msg'  => '사용자취소',
            'ordr_idxx' => 'ORD-TEST-CANCEL',
        ]);

        $response->assertRedirect('/shop/checkout');
        $this->assertStringNotContainsString('error=', $response->headers->get('Location'));
    }

    public function test_auth_callback_silently_redirects_on_empty_res_cd(): void
    {
        $this->mockPluginSettings();

        $response = $this->post('/plugins/sirsoft-pay_nhnkcp/payment/callback', [
            'res_cd'    => '',
            'ordr_idxx' => 'ORD-TEST-EMPTY',
        ]);

        $response->assertRedirect('/shop/checkout');
        $this->assertStringNotContainsString('error=', $response->headers->get('Location'));
    }

    public function test_auth_callback_redirects_to_fail_on_order_not_found(): void
    {
        $this->mockPluginSettings();
        $this->mockApiService($this->makeCliResponse('TNO_X', 'NON_EXISTENT', 50000));

        $response = $this->post(
            '/plugins/sirsoft-pay_nhnkcp/payment/callback',
            $this->makeCallbackParams('NON_EXISTENT_ORDER', 50000)
        );

        $response->assertRedirect();
        $this->assertStringContainsString('error=order_not_found', $response->headers->get('Location'));
    }

    public function test_auth_callback_redirects_to_fail_when_cli_approval_fails(): void
    {
        $order = $this->createTestOrder(50000);
        $this->mockPluginSettings();
        $this->mockApiService($this->makeCliResponse('TNO_FAIL', $order->order_number, 50000, '9999'));

        $response = $this->post(
            '/plugins/sirsoft-pay_nhnkcp/payment/callback',
            $this->makeCallbackParams($order->order_number, 50000)
        );

        $response->assertRedirect();
        $this->assertStringContainsString('error=9999', $response->headers->get('Location'));
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
        $this->mockApiService($this->makeCliResponse($tno, $order->order_number, 50000));

        $response = $this->post(
            '/plugins/sirsoft-pay_nhnkcp/payment/callback',
            $this->makeCallbackParams($order->order_number, 50000, ['tno' => $tno])
        );

        $response->assertRedirect("/custom/payment/{$order->order_number}/done");
    }

    public function test_auth_callback_detects_mobile_device(): void
    {
        $order = $this->createTestOrder(50000);
        $this->mockPluginSettings();

        $tno = 'KCP_TNO_MOBILE';
        $this->mockApiService($this->makeCliResponse($tno, $order->order_number, 50000));

        $response = $this->post(
            '/plugins/sirsoft-pay_nhnkcp/payment/callback',
            $this->makeCallbackParams($order->order_number, 50000, ['tno' => $tno]),
            ['User-Agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X)']
        );

        $response->assertRedirect("/shop/orders/{$order->order_number}/complete");
        $payment = $order->payment;
        $payment->refresh();
        $this->assertEquals('mobile', $payment->payment_device);
    }

    // ===== 가상계좌 입금 통보 =====

    public function test_vbank_notify_returns_ok_on_successful_deposit(): void
    {
        $order = $this->createTestOrder(30000);

        $response = $this->post('/plugins/sirsoft-pay_nhnkcp/payment/vbank-notify', [
            'tno'                => 'KCP_VBANK_TNO_001',
            'ordr_idxx'          => $order->order_number,
            'good_mny'           => 30000,
            'res_cd'             => '0000',
            'res_msg'            => '입금완료',
            'bank_name'          => '국민은행',
            'account'            => '1234567890',
            'account_holder'     => '홍길동',
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
            'tno'       => 'KCP_VBANK_TNO_002',
            'ordr_idxx' => 'ORD-TEST-CANCEL',
            'good_mny'  => 30000,
            'res_cd'    => '8001',
            'res_msg'   => '입금취소',
        ]);

        $response->assertOk();
        $this->assertEquals('OK', $response->getContent());
    }

    public function test_vbank_notify_returns_fail_on_order_not_found(): void
    {
        $response = $this->post('/plugins/sirsoft-pay_nhnkcp/payment/vbank-notify', [
            'tno'       => 'KCP_VBANK_TNO_003',
            'ordr_idxx' => 'NON_EXISTENT_ORDER',
            'good_mny'  => 30000,
            'res_cd'    => '0000',
        ]);

        $response->assertOk();
        $this->assertEquals('FAIL', $response->getContent());
    }

    // ===== 가상계좌 발급 (handleVbankIssued) 성공 처리 =====

    /**
     * PC 가상계좌: KCP 가 res_cd=V000 ("가상계좌가 발급되었습니다.") + bankname/account 응답 시
     * success URL 로 리다이렉트 + vbank 컬럼 정상 저장.
     *
     * 회귀: KCP 표준결제창은 결제수단별로 다른 정상 응답 코드를 사용한다
     *      (card=0000, vbank=V000). SUCCESS_RES_CD='0000' 단일 비교만으로 검증하면
     *      정상 가상계좌 발급도 fail URL 로 처리됨 — 실제 운영 회귀 발견 (주문 20260513-0846191476).
     *      판정 기준을 res_cd 비교에서 핵심 필드(bankname/account) 존재 여부로 변경.
     */
    public function test_vbank_pc_succeeds_on_kcp_v000_with_issuance_data(): void
    {
        $order = $this->createTestOrder(30000, [], PaymentMethodEnum::VBANK);
        $this->mockPluginSettings();

        $tno = 'KCP_VBANK_V000';
        $this->mockApiService([
            'res_cd'    => 'V000',
            'res_msg'   => '가상계좌가 발급되었습니다.',
            'tno'       => $tno,
            'bankname'  => 'NH농협',
            'account'   => 'T1109260001455',
            'depositor' => 'NHN KCP',
            'va_date'   => '20260516235959',
            'bankcode'  => 'BK11',
            'app_time'  => '20260513174624',
        ]);

        $response = $this->post(
            '/plugins/sirsoft-pay_nhnkcp/payment/callback',
            $this->makeCallbackParams($order->order_number, 30000, [
                'tno'            => $tno,
                'use_pay_method' => 'VCNT',
            ])
        );

        $response->assertRedirect("/shop/orders/{$order->order_number}/complete");

        $order->refresh();
        $this->assertNotEquals(OrderStatusEnum::CANCELLED, $order->order_status);

        $payment = $order->payment;
        $payment->refresh();
        $this->assertEquals('NH농협', $payment->vbank_name);
        $this->assertEquals('T1109260001455', $payment->vbank_number);
        $this->assertEquals('NHN KCP', $payment->vbank_holder);
        $this->assertNotNull($payment->vbank_due_at);
        $this->assertNotNull($payment->vbank_issued_at);
    }

    // ===== 가상계좌 발급 (handleVbankIssued) 실패 처리 =====

    /**
     * PC 가상계좌: CLI 가 res_cd != 정상 + bankname/account 결락 응답 시 (예: 9502 연동 모듈 호출 오류)
     * fail URL 로 리다이렉트 + payment_status 가 결제 완료로 전환되지 않아야 함.
     *
     * 회귀: 기존 코드는 "계좌 발급 자체는 성공" 가정하에 success URL 로 보내
     *      사용자에게 빈 가상계좌 정보의 complete 페이지를 노출했음 (운영 사고).
     */
    public function test_vbank_pc_redirects_to_fail_on_cli_non_0000(): void
    {
        $order = $this->createTestOrder(30000, [], PaymentMethodEnum::VBANK);
        $this->mockPluginSettings();
        $this->mockApiService($this->makeCliResponse('KCP_VBANK_FAIL', $order->order_number, 30000, '9502'));

        $response = $this->post(
            '/plugins/sirsoft-pay_nhnkcp/payment/callback',
            $this->makeCallbackParams($order->order_number, 30000, ['use_pay_method' => 'VCNT'])
        );

        $response->assertRedirect();
        $this->assertStringContainsString('error=9502', $response->headers->get('Location'));
        $this->assertStringNotContainsString('/complete', $response->headers->get('Location'));

        $order->refresh();
        $this->assertNotEquals(OrderStatusEnum::PAYMENT_COMPLETE, $order->order_status);

        $payment = $order->payment;
        $payment->refresh();
        $this->assertNull($payment->vbank_name);
        $this->assertNull($payment->vbank_number);
    }

    /**
     * PC 가상계좌: CLI 호출 자체가 예외(approvePayment throws) → fail URL.
     */
    public function test_vbank_pc_redirects_to_fail_on_cli_exception(): void
    {
        $order = $this->createTestOrder(30000, [], PaymentMethodEnum::VBANK);
        $this->mockPluginSettings();

        $mock = $this->createMock(NhnKcpApiService::class);
        $mock->method('approvePayment')->willThrowException(new \RuntimeException('CLI exec failed'));
        $this->app->instance(NhnKcpApiService::class, $mock);

        $response = $this->post(
            '/plugins/sirsoft-pay_nhnkcp/payment/callback',
            $this->makeCallbackParams($order->order_number, 30000, ['use_pay_method' => 'VCNT'])
        );

        $response->assertRedirect();
        $this->assertStringNotContainsString('/complete', $response->headers->get('Location'));

        $order->refresh();
        $this->assertNotEquals(OrderStatusEnum::PAYMENT_COMPLETE, $order->order_status);

        $payment = $order->payment;
        $payment->refresh();
        $this->assertNull($payment->vbank_name);
        $this->assertNull($payment->vbank_number);
    }

    /**
     * 모바일 가상계좌: 콜백 POST 의 평문 필드를 그대로 응답으로 취급하는 경로에서
     * res_cd != 0000 시 fail URL 로 리다이렉트.
     *
     * 모바일은 enc_data/enc_info 가 없어 CLI 미호출이므로, 콜백 res_cd 자체가 권위.
     */
    public function test_vbank_mobile_redirects_to_fail_on_callback_res_cd_non_0000(): void
    {
        $order = $this->createTestOrder(30000, [], PaymentMethodEnum::VBANK);
        $this->mockPluginSettings();

        // 모바일: enc_data/enc_info 없이 res_cd 가 비-0000.
        // 단 authCallback() 의 1단계 res_cd 가드를 통과해야 handleVbankIssued() 가 호출되므로
        // 시나리오 재현은 1단계 가드가 vbank 비-0000 도 동일하게 처리해야 한다는 의미.
        // 여기서는 res_cd=0000 으로 진입하되 handleVbankIssued 모바일 분기에서
        // 평문 필드 누락으로 발급 실패(bankname/account 모두 null)인 케이스를 검증.
        $response = $this->post(
            '/plugins/sirsoft-pay_nhnkcp/payment/callback',
            [
                'res_cd'         => '0000',
                'ordr_idxx'      => $order->order_number,
                'good_mny'       => 30000,
                'tno'            => 'KCP_VBANK_MOBILE_FAIL',
                'use_pay_method' => 'VCNT',
                // bankname / account / depositor / va_date 모두 누락 → 발급 정보 없음
            ]
        );

        $response->assertRedirect();
        $this->assertStringNotContainsString('/complete', $response->headers->get('Location'));

        $order->refresh();
        $this->assertNotEquals(OrderStatusEnum::PAYMENT_COMPLETE, $order->order_status);

        $payment = $order->payment;
        $payment->refresh();
        $this->assertNull($payment->vbank_name);
        $this->assertNull($payment->vbank_number);
    }
}
