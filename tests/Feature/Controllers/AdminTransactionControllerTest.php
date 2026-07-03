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

class AdminTransactionControllerTest extends PluginTestCase
{
    public function test_transaction_status_includes_easy_pay_display_label(): void
    {
        app()->setLocale('ko');

        $admin = $this->createAdminUser(['sirsoft-ecommerce.orders.read']);
        $order = OrderFactory::new()->create([
            'user_id' => User::factory()->create()->id,
            'order_number' => 'ORD-KCP-ADMIN-' . random_int(10000, 99999),
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
            'transaction_id' => 'KCP_TNO_KAKAOPAY',
            'embedded_pg_provider' => 'kakaopay',
            'paid_amount_local' => 50000,
            'paid_at' => now(),
            'payment_meta' => [
                'nhnkcp_easy_pay_method' => 'nhnkcp_kakaopay',
                'nhnkcp_easy_pay_provider' => 'kakaopay',
                'nhnkcp_easy_pay_label' => [
                    'ko' => '카카오페이',
                    'en' => 'KakaoPay',
                ],
                'pg_raw_response' => [
                    'app_no' => 'APP12345',
                    'use_pay_method' => 'CARD',
                ],
            ],
        ]);

        $response = $this->actingAs($admin)
            ->getJson("/api/plugins/sirsoft-pay_nhnkcp/admin/orders/{$order->order_number}/transaction-status");

        $response->assertOk()
            ->assertJsonPath('data._embedded_pg_provider', 'kakaopay');

        $data = $response->json('data');
        $this->assertContains($data['_embedded_pg_provider_label'] ?? null, ['카카오페이', 'KakaoPay']);
        $this->assertSame(
            ($data['_embedded_pg_provider_label'] ?? '') . ' (' . ($data['_base_pay_method_label'] ?? '') . ')',
            $data['_pay_method_label'] ?? null,
        );
    }
}
