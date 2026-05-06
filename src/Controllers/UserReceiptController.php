<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\Pay\Nhnkcp\Controllers;

use App\Services\PluginSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Plugins\Sirsoft\Pay\Nhnkcp\Services\NhnKcpApiService;

class UserReceiptController
{
    private const PLUGIN_IDENTIFIER = 'sirsoft-pay-nhnkcp';

    // 카드/계좌이체 영수증 URL (cmd= 로 끝나므로 cmd 값을 바로 붙임)
    private const BILL_URL_TEST = 'https://testadmin8.kcp.co.kr/assist/bill.BillActionNew.do?cmd=';

    private const BILL_URL_LIVE = 'https://admin8.kcp.co.kr/assist/bill.BillActionNew.do?cmd=';

    // 현금영수증 URL (term_id=PGNW 로 끝나므로 site_cd 값을 바로 붙임)
    private const CASH_URL_TEST = 'https://testadmin8.kcp.co.kr/Modules/Service/Cash/Cash_Bill_Common_View.jsp?term_id=PGNW';

    private const CASH_URL_LIVE = 'https://admin.kcp.co.kr/Modules/Service/Cash/Cash_Bill_Common_View.jsp?term_id=PGNW';

    public function __construct(
        private readonly PluginSettingsService $pluginSettingsService,
        private readonly NhnKcpApiService $apiService,
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
            ->select([
                'p.transaction_id',
                'p.payment_meta',
                'p.payment_method',
                'p.payment_device',
                'p.paid_amount_local',
                'o.order_number',
            ])
            ->first();

        if (! $payment || ! $payment->transaction_id) {
            return response()->json(['error' => 'Not found'], 404);
        }

        $settings = $this->pluginSettingsService->get(self::PLUGIN_IDENTIFIER) ?? [];
        $isTest = (bool) ($settings['is_test_mode'] ?? true);

        $billBaseUrl = $isTest ? self::BILL_URL_TEST : self::BILL_URL_LIVE;
        $cashBaseUrl = $isTest ? self::CASH_URL_TEST : self::CASH_URL_LIVE;

        $tno = $payment->transaction_id;
        $orderNo = $payment->order_number;
        $tradeMony = (int) round((float) ($payment->paid_amount_local ?? 0));
        $method = $payment->payment_method ?? '';

        // 결제수단 분류
        $isMobile = $method === 'mobile' || $payment->payment_device === 'mobile';
        $isCashMethod = in_array($method, ['bank_transfer', 'vbank'], true);

        // 카드/계좌이체/모바일 영수증 URL
        $billCmd = $isMobile ? 'mcash_bill' : 'card_bill';
        $receiptUrl = $billBaseUrl . $billCmd
            . '&tno=' . urlencode($tno)
            . '&order_no=' . urlencode($orderNo)
            . '&trade_mony=' . $tradeMony;

        // 현금영수증 URL (계좌이체 · 가상계좌만 해당)
        $cashReceiptUrl = null;
        if ($isCashMethod) {
            $meta = $payment->payment_meta ? json_decode($payment->payment_meta, true) : [];
            $pgRaw = $meta['pg_raw_response'] ?? $meta;
            $authNo = $pgRaw['app_no'] ?? $pgRaw['receipt_no'] ?? $tno;
            $siteCd = $this->apiService->getSiteCd();

            $cashReceiptUrl = $cashBaseUrl . $siteCd
                . '&orderid=' . urlencode($orderNo)
                . '&bill_yn=Y'
                . '&authno=' . urlencode((string) $authNo);
        }

        return response()->json([
            'receipt_url'      => $receiptUrl,
            'cash_receipt_url' => $cashReceiptUrl,
            'is_test_mode'     => $isTest,
        ]);
    }
}
