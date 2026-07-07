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

    public function test_adds_test_mode_warning_to_order_settings_tab(): void
    {
        $listener = new AdjustEcommercePaymentMethodsLayoutListener();

        $layout = [
            'layout_name' => 'admin_ecommerce_settings',
            'children' => [
                [
                    'id' => 'tab_content_order_settings',
                    'children' => [
                        ['id' => 'default_pg_card'],
                        ['id' => 'payment_methods_card'],
                    ],
                ],
            ],
        ];

        $result = $listener->markEasyPayMethodsAsPgNotRequired($layout, 1);
        $result = $listener->markEasyPayMethodsAsPgNotRequired($result, 1);
        $json = json_encode($result, JSON_UNESCAPED_SLASHES);

        $this->assertIsString($json);
        $this->assertStringContainsString('nhnkcp_test_mode_status', $json);
        $this->assertStringContainsString('/api/plugins/sirsoft-pay_nhnkcp/admin/settings/test-mode-status', $json);
        $this->assertStringContainsString('payment_test_mode_order_settings_notice', $json);
        $this->assertStringContainsString('nhnkcp_test_mode_order_settings_notice', $json);
        $this->assertStringNotContainsString('sirsoft-pay_nhnkcp.admin.test_mode_settings_summary_title', $json);
        $this->assertStringNotContainsString('sirsoft-pay_nhnkcp.admin.test_mode_settings_summary_body', $json);
        $this->assertStringContainsString('sirsoft-pay_nhnkcp.admin.test_mode_settings_warning_plugin', $json);
        $this->assertStringContainsString('sirsoft-pay_nhnkcp.admin.test_mode_settings_warning_body', $json);
        $this->assertStringContainsString('/admin/plugins/sirsoft-pay_nhnkcp/settings', $json);
        $this->assertSame(1, substr_count($json, 'payment_test_mode_order_settings_notice'));
        $this->assertSame(1, substr_count($json, 'nhnkcp_test_mode_order_settings_notice'));
        $this->assertLessThan(
            strpos($json, 'payment_methods_card'),
            strpos($json, 'payment_test_mode_order_settings_notice')
        );
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
