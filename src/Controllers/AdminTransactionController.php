<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNhnkcp\Controllers;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use App\Services\PluginSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Plugins\Sirsoft\PayNhnkcp\Concerns\ResolvesEasyPayDisplay;

/**
 * NHN KCP 거래 정보 관리자 컨트롤러
 *
 * KCP는 CLI 바이너리 방식으로 승인이 이루어지므로 웹 API 실시간 조회가 불가합니다.
 * DB에 저장된 거래 정보(payment_meta)를 반환합니다.
 */
class AdminTransactionController extends AdminBaseController
{
    use ResolvesEasyPayDisplay;

    private const PLUGIN_IDENTIFIER = 'sirsoft-pay_nhnkcp';

    public function __construct(
        private readonly PluginSettingsService $pluginSettingsService,
    ) {
        parent::__construct();
    }

    /**
     * GET /api/plugins/sirsoft-pay_nhnkcp/admin/orders/{orderNumber}/transaction-status
     */
    /**
     * 주문번호로 KCP 거래 조회
     *
     * 어드민 주문 상세에서 거래 조회 버튼 클릭 시 사용. ecommerce_order_payments
     * 의 transaction_id (KCP tno) 를 찾아 KCP 단건 거래 조회 API 호출.
     *
     * @param  string  $orderNumber  주문번호
     * @return JsonResponse 거래 정보 또는 매핑 없을 시 null
     */
    public function queryByOrder(string $orderNumber): JsonResponse
    {
        $payment = DB::table('ecommerce_order_payments as p')
            ->join('ecommerce_orders as o', 'o.id', '=', 'p.order_id')
            ->where('o.order_number', $orderNumber)
            ->where('p.pg_provider', 'nhnkcp')
            ->whereNotNull('p.transaction_id')
            ->where('p.transaction_id', '!=', '')
            ->select(['p.transaction_id', 'p.payment_meta', 'p.payment_method', 'p.embedded_pg_provider'])
            ->first();

        if (! $payment) {
            return ResponseHelper::success('messages.success', null);
        }

        $settings = $this->pluginSettingsService->get(self::PLUGIN_IDENTIFIER) ?? [];
        $isTest = (bool) ($settings['is_test_mode'] ?? true);

        $meta = $this->decodePaymentMeta($payment->payment_meta ?? null);
        $rawResponse = $meta['pg_raw_response'] ?? [];
        $display = $this->resolvePaymentDisplay($payment);

        return ResponseHelper::success('messages.success', [
            'tno'            => $payment->transaction_id,
            'app_no'         => $rawResponse['app_no'] ?? $meta['app_no'] ?? null,
            'use_pay_method' => $meta['use_pay_method'] ?? $rawResponse['use_pay_method'] ?? null,
            'app_time'       => $meta['app_time'] ?? $rawResponse['app_time'] ?? null,
            'res_cd'         => $meta['res_cd'] ?? $rawResponse['res_cd'] ?? '0000',
            'card_name'      => $rawResponse['card_name'] ?? $meta['card_name'] ?? $rawResponse['bank_name'] ?? $meta['bank_name'] ?? null,
            'account'        => $meta['account'] ?? null,
            'bank_name'      => $rawResponse['bank_name'] ?? $meta['bank_name'] ?? null,
            '_is_test_mode'  => $isTest,
            '_base_pay_method_label' => $display['payment_method_label'],
            '_embedded_pg_provider' => $display['embedded_pg_provider'],
            '_embedded_pg_provider_label' => $display['embedded_pg_provider_label'],
            '_pay_method_label' => $display['payment_method_display_label'],
            'payment_method_display_label' => $display['payment_method_display_label'],
        ]);
    }
}
