<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\Pay\Nhnkcp\Controllers;

use App\Services\PluginSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UserReceiptController
{
    private const PLUGIN_IDENTIFIER = 'sirsoft-pay-nhnkcp';

    private const RECEIPT_URL_TEST = 'https://testpay.kcp.co.kr/plugin/rReceipt.jsp';

    private const RECEIPT_URL_LIVE = 'https://pay.kcp.co.kr/plugin/rReceipt.jsp';

    public function __construct(
        private readonly PluginSettingsService $pluginSettingsService,
    ) {}

    /**
     * GET /api/plugins/sirsoft-pay-nhnkcp/user/orders/{orderNumber}/receipt
     */
    public function show(Request $request, string $orderNumber): JsonResponse
    {
        $user = $request->user();

        $payment = DB::table('ecommerce_order_payments as p')
            ->join('ecommerce_orders as o', 'o.id', '=', 'p.order_id')
            ->where('o.order_number', $orderNumber)
            ->where('o.user_id', $user->id)
            ->where('p.pg_provider', 'nhnkcp')
            ->whereNotNull('p.transaction_id')
            ->select(['p.transaction_id', 'p.payment_meta'])
            ->first();

        if (! $payment || ! $payment->transaction_id) {
            return response()->json(['error' => 'Not found'], 404);
        }

        $settings = $this->pluginSettingsService->get(self::PLUGIN_IDENTIFIER) ?? [];
        $isTest = (bool) ($settings['is_test_mode'] ?? true);

        $meta = $payment->payment_meta ? json_decode($payment->payment_meta, true) : [];
        $usePayMethod = $meta['use_pay_method'] ?? ($meta['pg_raw_response']['use_pay_method'] ?? '100000000000');

        $baseUrl = $isTest ? self::RECEIPT_URL_TEST : self::RECEIPT_URL_LIVE;
        $receiptUrl = $baseUrl . '?' . http_build_query([
            'nConfirm'  => $payment->transaction_id,
            'PayMethod' => $usePayMethod,
            'noti_type' => 'C',
            'nDirect'   => '1',
        ]);

        return response()->json([
            'receipt_url'  => $receiptUrl,
            'is_test_mode' => $isTest,
        ]);
    }
}
