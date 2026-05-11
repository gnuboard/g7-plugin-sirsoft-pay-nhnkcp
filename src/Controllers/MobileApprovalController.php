<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNhnkcp\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Sirsoft\Ecommerce\Services\OrderProcessingService;
use Plugins\Sirsoft\PayNhnkcp\Exceptions\NhnKcpApiException;
use Plugins\Sirsoft\PayNhnkcp\Services\KcpSoapService;

/**
 * KCP SmartPhone Pay 승인키 컨트롤러
 *
 * PC Standard Pay(iframe)와 달리 모바일은 서버에서 SOAP으로 approval_key 를
 * 먼저 획득하고, 브라우저가 pay_url 로 form POST(페이지 전환)한다.
 */
class MobileApprovalController
{
    /** 결제수단 → KCP 모바일 pay_method 코드 */
    private const MOBILE_PAY_METHOD_MAP = [
        'card' => 'CARD',
        'bank_transfer' => 'BANK',
        'virtual_account' => 'VCNT',
        'mobile' => 'MOBX',
        'bank' => 'BANK',
        'vbank' => 'VCNT',
        'phone' => 'MOBX',
    ];

    /** KCP 모바일 pay_method → ActionResult (form hidden field) */
    private const ACTION_RESULT_MAP = [
        'CARD' => 'card',
        'BANK' => 'acnt',
        'MOBX' => 'mobx',
        'VCNT' => 'vcnt',
    ];

    /** 간편결제별 direct 파라미터 */
    private const EASY_PAY_DIRECT_FIELDS = [
        'nhnkcp_payco' => ['payco_direct' => 'Y'],
        'nhnkcp_naverpay' => ['naverpay_direct' => 'Y'],
        'nhnkcp_naverpay_point' => ['naverpay_direct' => 'Y', 'naverpay_point_direct' => 'Y'],
        'nhnkcp_kakaopay' => ['kakaopay_direct' => 'Y'],
        'nhnkcp_applepay' => ['applepay_direct' => 'Y'],
    ];

    public function __construct(
        private readonly KcpSoapService $soapService,
        private readonly OrderProcessingService $orderService,
    ) {}

    /**
     * KCP 모바일 결제 승인키 획득
     *
     * POST /api/plugins/sirsoft-pay_nhnkcp/mobile/approval-key
     *
     * @return JsonResponse{ success: true, data: { pay_url, fields } }
     */
    public function getApprovalKey(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'order_number' => ['required', 'string', 'max:40'],
            'amount' => ['required', 'integer', 'min:100'],
            'good_name' => ['required', 'string', 'max:100'],
            'pay_method' => ['required', 'string'],
            'buyr_name' => ['nullable', 'string', 'max:50'],
            'buyr_mail' => ['nullable', 'string', 'email', 'max:100'],
            'buyr_tel1' => ['nullable', 'string', 'max:20'],
            'ret_url' => ['required', 'string', 'url'],
        ]);

        $payMethodKey = strtolower($validated['pay_method']);
        $isEasyPay = str_starts_with($payMethodKey, 'nhnkcp_');
        $mobilePayMethod = $isEasyPay
            ? 'CARD'
            : (self::MOBILE_PAY_METHOD_MAP[$payMethodKey] ?? 'CARD');

        try {
            $result = $this->soapService->getApprovalKey(
                orderNumber: $validated['order_number'],
                goodName: $validated['good_name'],
                amount: (int) $validated['amount'],
                payMethod: $mobilePayMethod,
                retUrl: $validated['ret_url'],
                payMethodKey: $payMethodKey,
                escrow: false,
            );

            $fields = [
                'req_tx' => 'pay',
                'site_cd' => $this->soapService->getSiteCd($payMethodKey),
                'ordr_idxx' => $validated['order_number'],
                'pay_method' => $mobilePayMethod,
                'good_mny' => (string) $validated['amount'],
                'good_name' => $validated['good_name'],
                'buyr_name' => $validated['buyr_name'] ?? '',
                'buyr_mail' => $validated['buyr_mail'] ?? '',
                'buyr_tel1' => $validated['buyr_tel1'] ?? '',
                'Ret_URL' => $validated['ret_url'],
                'ActionResult' => self::ACTION_RESULT_MAP[$mobilePayMethod] ?? 'card',
                'escw_used' => 'N',
                'quotaopt' => '12',
                'currency' => '410',
                'approval_key' => $result['approval_key'],
                ...$this->buildTaxFields($validated['order_number']),
            ];

            if ($isEasyPay && isset(self::EASY_PAY_DIRECT_FIELDS[$payMethodKey])) {
                $fields = [...$fields, ...self::EASY_PAY_DIRECT_FIELDS[$payMethodKey]];
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'pay_url' => $result['pay_url'],
                    'fields' => $fields,
                ],
            ]);

        } catch (NhnKcpApiException $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * 주문의 과세/비과세 분할 필드 반환 (복합과세 사이트 코드 계약 시 필요)
     *
     * 비과세 금액이 있는 경우에만 comm_tax_mny / comm_vat_mny / comm_free_mny 추가.
     * 전액 과세라면 good_mny 만으로 KCP가 내부적으로 세금 계산.
     *
     * @return array<string, string>
     */
    private function buildTaxFields(string $orderNumber): array
    {
        $order = $this->orderService->findByOrderNumber($orderNumber);
        if (! $order) {
            return [];
        }

        $taxFreeAmt = (int) round((float) ($order->total_tax_free_amount ?? 0));
        if ($taxFreeAmt <= 0) {
            return [];
        }

        $taxTotalAmt = (int) round((float) ($order->total_tax_amount ?? 0));
        $vatAmt = (int) round((float) ($order->total_vat_amount ?? 0));
        $supplyAmt = $taxTotalAmt - $vatAmt; // 공급가액 (VAT 제외)

        return [
            'tax_flag'      => 'TG03',
            'comm_tax_mny'  => (string) $supplyAmt,
            'comm_vat_mny'  => (string) $vatAmt,
            'comm_free_mny' => (string) $taxFreeAmt,
        ];
    }
}
