<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNhnkcp\Tests\Feature\Controllers;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderPaymentFactory;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Plugins\Sirsoft\PayNhnkcp\Tests\PluginTestCase;

class UserReceiptControllerTest extends PluginTestCase
{
    public function test_receipt_response_includes_easy_pay_display_label(): void
    {
        app()->setLocale('ko');

        $user = User::factory()->create();
        $order = OrderFactory::new()->create([
            'user_id' => $user->id,
            'order_number' => 'ORD-KCP-RECEIPT-' . random_int(10000, 99999),
            'order_status' => OrderStatusEnum::PAYMENT_COMPLETE,
            'total_amount' => 50000,
            'total_due_amount' => 0,
            'total_paid_amount' => 50000,
            'paid_at' => now(),
        ]);

        OrderPaymentFactory::new()->create([
            'order_id' => $order->id,
            'payment_status' => PaymentStatusEnum::PAID,
            'payment_method' => PaymentMethodEnum::CARD,
            'pg_provider' => 'nhnkcp',
            'transaction_id' => 'KCP_TNO_NAVERPAY',
            'embedded_pg_provider' => 'naverpay',
            'paid_amount_local' => 50000,
            'paid_at' => now(),
            'payment_meta' => [
                'nhnkcp_easy_pay_method' => 'nhnkcp_naverpay',
                'nhnkcp_easy_pay_provider' => 'naverpay',
                'nhnkcp_easy_pay_label' => [
                    'ko' => '네이버페이',
                    'en' => 'NaverPay',
                ],
            ],
        ]);

        $response = $this->actingAs($user)
            ->getJson("/api/plugins/sirsoft-pay_nhnkcp/user/orders/{$order->order_number}/receipt");

        $response->assertOk()
            ->assertJsonPath('selected_payment_method', 'nhnkcp_naverpay')
            ->assertJsonPath('embedded_pg_provider', 'naverpay');

        $data = $response->json();
        $this->assertContains($data['embedded_pg_provider_label'] ?? null, ['네이버페이', 'NaverPay']);
        $this->assertSame(
            ($data['embedded_pg_provider_label'] ?? '') . ' (' . ($data['payment_method_label'] ?? '') . ')',
            $data['payment_method_display_label'] ?? null,
        );
    }
}
