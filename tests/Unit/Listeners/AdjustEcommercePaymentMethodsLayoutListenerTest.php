<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNhnkcp\Tests\Unit\Listeners;

use PHPUnit\Framework\TestCase;
use Plugins\Sirsoft\PayNhnkcp\Listeners\AdjustEcommercePaymentMethodsLayoutListener;

class AdjustEcommercePaymentMethodsLayoutListenerTest extends TestCase
{
    public function test_subscribes_to_layout_after_apply_as_filter(): void
    {
        $hooks = AdjustEcommercePaymentMethodsLayoutListener::getSubscribedHooks();

        $this->assertSame(
            'filter',
            $hooks['core.layout_extension.after_apply']['type'] ?? null
        );
        $this->assertSame(
            'markEasyPayMethodsAsPgNotRequired',
            $hooks['core.layout_extension.after_apply']['method'] ?? null
        );
    }

    public function test_marks_nhnkcp_easy_pay_methods_as_pg_not_required_in_admin_ecommerce_settings(): void
    {
        $listener = new AdjustEcommercePaymentMethodsLayoutListener();

        $layout = [
            'layout_name' => 'admin_ecommerce_settings',
            'components' => [
                [
                    'type' => 'basic',
                    'name' => 'Select',
                    'if' => "{{!['point','deposit','free','dbank'].includes(\$method.id) && (_local.form?.available_pg_providers ?? []).length > 0}}",
                ],
                [
                    'type' => 'composite',
                    'name' => 'Toggle',
                    'props' => [
                        'disabled' => "{{_computed.isReadOnly || !['point','deposit','free','dbank','kginicis_naverpay'].includes(\$method.id) && !\$method.pg_provider && !_local.form?.order_settings?.default_pg_provider}}",
                    ],
                ],
            ],
        ];

        $result = $listener->markEasyPayMethodsAsPgNotRequired($layout, 1);
        $json = json_encode($result, JSON_UNESCAPED_SLASHES);

        $this->assertIsString($json);
        $this->assertStringContainsString('kginicis_naverpay', $json);
        $this->assertStringContainsString('nhnkcp_payco', $json);
        $this->assertStringContainsString('nhnkcp_naverpay', $json);
        $this->assertStringContainsString('nhnkcp_naverpay_point', $json);
        $this->assertStringContainsString('nhnkcp_kakaopay', $json);
        $this->assertStringContainsString('nhnkcp_applepay', $json);
        $this->assertStringNotContainsString("['point','deposit','free','dbank'].includes", $json);
    }

    public function test_leaves_other_layouts_unchanged(): void
    {
        $listener = new AdjustEcommercePaymentMethodsLayoutListener();

        $layout = [
            'layout_name' => 'shop/checkout',
            'components' => [
                [
                    'if' => "{{!['point','deposit','free','dbank'].includes(\$method.id)}}",
                ],
            ],
        ];

        $this->assertSame($layout, $listener->markEasyPayMethodsAsPgNotRequired($layout, 1));
    }
}
