<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNhnkcp\Tests\Unit\Listeners;

use Plugins\Sirsoft\PayNhnkcp\Listeners\AdjustEcommercePaymentMethodsLayoutListener;
use Plugins\Sirsoft\PayNhnkcp\Listeners\RegisterEasyPayMethodsListener;
use Plugins\Sirsoft\PayNhnkcp\Plugin;
use Tests\TestCase;

class RegisterEasyPayMethodsListenerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app['translator']->addNamespace(
            'sirsoft-pay_nhnkcp',
            base_path('plugins/_bundled/sirsoft-pay_nhnkcp/lang')
        );
    }

    public function test_injects_easy_pay_methods_after_phone_and_existing_easy_pay_methods(): void
    {
        $listener = new RegisterEasyPayMethodsListener;

        $methods = $listener->injectEasyPayMethods([
            ['id' => 'card'],
            ['id' => 'phone'],
            ['id' => 'kginicis_samsung_pay'],
            ['id' => 'kginicis_naverpay'],
            ['id' => 'point'],
            ['id' => 'deposit'],
        ]);

        $this->assertSame([
            'card',
            'phone',
            'kginicis_samsung_pay',
            'kginicis_naverpay',
            'nhnkcp_payco',
            'nhnkcp_naverpay',
            'nhnkcp_naverpay_point',
            'nhnkcp_kakaopay',
            'nhnkcp_applepay',
            'point',
            'deposit',
        ], array_column($methods, 'id'));
    }

    public function test_plugin_registers_easy_pay_hook_listeners(): void
    {
        $listeners = (new Plugin)->getHookListeners();

        $this->assertContains(RegisterEasyPayMethodsListener::class, $listeners);
        $this->assertContains(AdjustEcommercePaymentMethodsLayoutListener::class, $listeners);
    }

    public function test_easy_pay_methods_are_locked_to_own_pg_provider(): void
    {
        // 간편결제는 NHN KCP 결제창을 통해서만 처리되므로 PG 를 자기 자신으로 고정 선언한다.
        //
        // 과거에는 pg_provider 를 null 로 두었고(= "PG 없는 결제수단"), 그 결과 서버가
        // 간편결제 주문을 PG 결제가 아닌 주문으로 오인해 (a) 결제 실패했는데 관리자에게
        // 신규주문 알림이 발송되고 (b) 임시주문이 즉시 삭제되어 재결제가 불가능해졌다(#475).
        $listener = new RegisterEasyPayMethodsListener;

        $methods = $listener->injectEasyPayMethods([
            ['id' => 'phone'],
            ['id' => 'point'],
        ]);

        $easyPayMethods = array_filter(
            $methods,
            fn (array $method): bool => str_starts_with((string) ($method['id'] ?? ''), 'nhnkcp_')
        );

        $this->assertCount(5, $easyPayMethods);

        foreach ($easyPayMethods as $method) {
            $this->assertArrayHasKey('defaults', $method);

            // PG 고정 — null 이면 코어가 PG 없는 주문으로 오인한다.
            $this->assertSame('nhnkcp', $method['defaults']['pg_provider'] ?? null);
            $this->assertTrue($method['defaults']['pg_locked'] ?? false);
            $this->assertTrue($method['defaults']['needs_pg'] ?? false);
            $this->assertSame('pg', $method['defaults']['refund_method'] ?? null);

            $this->assertFalse($method['defaults']['is_active'] ?? true);
            $this->assertSame('payment_complete', $method['defaults']['stock_deduction_timing'] ?? null);
            $this->assertSame('payment_complete', $method['defaults']['mileage_deduction_timing'] ?? null);
        }
    }

    public function test_easy_pay_method_labels_match_admin_payment_method_names(): void
    {
        $listener = new RegisterEasyPayMethodsListener;

        $methods = $listener->injectEasyPayMethods([
            ['id' => 'phone'],
            ['id' => 'point'],
        ]);

        $naverpay = collect($methods)->firstWhere('id', 'nhnkcp_naverpay');
        $payco = collect($methods)->firstWhere('id', 'nhnkcp_payco');
        $applepay = collect($methods)->firstWhere('id', 'nhnkcp_applepay');

        $this->assertSame('네이버페이 (카드)', $naverpay['name']['ko'] ?? null);
        $this->assertSame('PAYCO', $payco['name']['ko'] ?? null);
        $this->assertSame('Pay by Naver Pay credit card (NHN KCP)', $naverpay['description']['en'] ?? null);
        $this->assertSame(
            '애플페이로 결제 (NHN KCP) · 애플페이는 IOS 기기에 모바일결제만 가능합니다.',
            $applepay['description']['ko'] ?? null,
        );
    }
}
