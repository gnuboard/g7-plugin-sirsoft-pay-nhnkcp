<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\Pay\Nhnkcp\Controllers;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use App\Services\PluginSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * NHN KCP 거래 정보 관리자 컨트롤러
 *
 * KCP는 CLI 바이너리 방식으로 승인이 이루어지므로 웹 API 실시간 조회가 불가합니다.
 * DB에 저장된 거래 정보(payment_meta)를 반환합니다.
 */
class AdminTransactionController extends AdminBaseController
{
    private const PLUGIN_IDENTIFIER = 'sirsoft-pay-nhnkcp';

    public function __construct(
        private readonly PluginSettingsService $pluginSettingsService,
    ) {
        parent::__construct();
    }

    /**
     * GET /api/plugins/sirsoft-pay-nhnkcp/admin/orders/{orderNumber}/transaction-status
     */
    public function queryByOrder(string $orderNumber): JsonResponse
    {
        $payment = DB::table('ecommerce_order_payments as p')
            ->join('ecommerce_orders as o', 'o.id', '=', 'p.order_id')
            ->where('o.order_number', $orderNumber)
            ->where('p.pg_provider', 'nhnkcp')
            ->whereNotNull('p.transaction_id')
            ->where('p.transaction_id', '!=', '')
            ->select(['p.transaction_id', 'p.payment_meta', 'p.payment_method'])
            ->first();

        if (! $payment) {
            return ResponseHelper::success('messages.success', null);
        }

        $settings = $this->pluginSettingsService->get(self::PLUGIN_IDENTIFIER) ?? [];
        $isTest = (bool) ($settings['is_test_mode'] ?? true);

        $meta = $payment->payment_meta ? json_decode($payment->payment_meta, true) : [];
        $rawResponse = $meta['pg_raw_response'] ?? [];

        return ResponseHelper::success('messages.success', [
            'tno'            => $payment->transaction_id,
            'app_no'         => $rawResponse['app_no'] ?? $meta['app_no'] ?? null,
            'use_pay_method' => $meta['use_pay_method'] ?? $rawResponse['use_pay_method'] ?? null,
            'app_time'       => $meta['app_time'] ?? $rawResponse['app_time'] ?? null,
            'res_cd'         => $meta['res_cd'] ?? $rawResponse['res_cd'] ?? '0000',
            'card_name'      => $rawResponse['card_name'] ?? $rawResponse['bank_name'] ?? null,
            'account'        => $rawResponse['account'] ?? null,
            'bank_name'      => $rawResponse['bank_name'] ?? null,
            '_is_test_mode'  => $isTest,
        ]);
    }
}
