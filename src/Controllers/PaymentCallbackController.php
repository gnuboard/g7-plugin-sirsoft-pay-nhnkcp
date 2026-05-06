<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\Pay\Nhnkcp\Controllers;

use App\Extension\HookManager;
use App\Services\PluginSettingsService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Exceptions\PaymentAmountMismatchException;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Services\OrderProcessingService;
use Plugins\Sirsoft\Pay\Nhnkcp\Http\Requests\AuthCallbackRequest;
use Plugins\Sirsoft\Pay\Nhnkcp\Http\Requests\VbankNotifyRequest;
use Plugins\Sirsoft\Pay\Nhnkcp\Services\NhnKcpApiService;

/**
 * KCP 결제 콜백 컨트롤러
 *
 * KCP Standard Pay는 브라우저 POST 콜백 방식입니다:
 *   1단계: 브라우저가 POST 콜백으로 enc_data, enc_info 등 전달 → authCallback()
 *   2단계: 서버가 KCP CLI 바이너리로 최종 승인 확인 → NhnKcpApiService::approvePayment()
 */
class PaymentCallbackController
{
    private const PLUGIN_IDENTIFIER = 'sirsoft-pay-nhnkcp';

    private const SUCCESS_RES_CD = '0000';

    public function __construct(
        private readonly OrderProcessingService $orderService,
        private readonly PluginSettingsService $pluginSettingsService,
        private readonly NhnKcpApiService $apiService,
    ) {}

    /**
     * KCP 결제 승인 콜백
     *
     * POST /plugins/sirsoft-pay-nhnkcp/payment/callback
     * (CSRF 제외 - KCP가 브라우저 통해 POST 전달)
     */
    public function authCallback(AuthCallbackRequest $request): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validated();

        $resCd = $validated['res_cd'];
        $resMsg = $validated['res_msg'] ?? '';
        $encData = $validated['enc_data'];
        $encInfo = $validated['enc_info'];
        $ordrIdxx = $validated['ordr_idxx'];
        $goodMny = isset($validated['good_mny']) ? (int) $validated['good_mny'] : 0;
        $custIp = $request->ip() ?? '127.0.0.1';

        Log::info('KCP: authCallback received', [
            'ordr_idxx' => $ordrIdxx,
            'res_cd' => $resCd,
            'good_mny' => $goodMny,
            'has_enc_data' => !empty($encData),
            'has_enc_info' => !empty($encInfo),
            'post_keys' => array_keys($request->all()),
        ]);

        // 1단계: KCP 브라우저 결과 코드 확인
        if ($resCd !== self::SUCCESS_RES_CD) {
            Log::warning('KCP: payment result failed', [
                'ordr_idxx' => $ordrIdxx,
                'res_cd' => $resCd,
                'res_msg' => $resMsg,
            ]);

            return redirect($this->resolveFailUrl([
                'error' => $resCd,
                'message' => $resMsg,
                'orderId' => $ordrIdxx,
            ]));
        }

        try {
            // 2단계: 주문 조회
            $order = $this->orderService->findByOrderNumber($ordrIdxx);

            if (! $order) {
                Log::error('KCP: order not found', ['ordr_idxx' => $ordrIdxx]);

                return redirect($this->resolveFailUrl(['error' => 'order_not_found', 'orderId' => $ordrIdxx]));
            }

            // 가상계좌: 계좌 발급 완료 처리 (실제 입금은 vbankNotify에서 처리)
            if (($validated['use_pay_method'] ?? '') === 'VCNT') {
                return $this->handleVbankIssued($validated, $order, $encData, $encInfo, $ordrIdxx, $custIp);
            }

            HookManager::doAction('sirsoft-pay-nhnkcp.payment.before_confirm', $order, $validated);

            // 3단계: KCP CLI로 최종 승인 확인
            $pgResponse = $this->apiService->approvePayment($encData, $encInfo, $ordrIdxx, $custIp);

            HookManager::doAction('sirsoft-pay-nhnkcp.payment.after_confirm', $order, $pgResponse);

            $pgResCd = $pgResponse['res_cd'] ?? '';

            if ($pgResCd !== self::SUCCESS_RES_CD) {
                Log::warning('KCP: CLI approval failed', [
                    'ordr_idxx' => $ordrIdxx,
                    'res_cd' => $pgResCd,
                    'res_msg' => $pgResponse['res_msg'] ?? '',
                ]);

                $this->orderService->failPayment($order, $pgResCd, $pgResponse['res_msg'] ?? '');

                return redirect($this->resolveFailUrl([
                    'error' => $pgResCd,
                    'message' => $pgResponse['res_msg'] ?? '',
                    'orderId' => $ordrIdxx,
                ]));
            }

            $tno = $pgResponse['tno'] ?? ($validated['tno'] ?? '');

            // KCP는 CLI 응답에 good_mny가 없는 경우가 많으므로 주문 금액으로 검증
            $approvedAmt = $goodMny > 0
                ? $goodMny
                : (int) round((float) $order->total_amount, 2);

            // 4단계: 주문 완료 처리
            $this->orderService->completePayment($order, [
                'transaction_id' => $tno,
                'card_approval_number' => $pgResponse['app_no'] ?? null,
                'card_number_masked' => $pgResponse['card_no'] ?? $pgResponse['account'] ?? null,
                'card_name' => $pgResponse['card_name'] ?? $pgResponse['bank_name'] ?? null,
                'card_installment_months' => (int) ($pgResponse['quota'] ?? 0),
                'is_interest_free' => false,
                'embedded_pg_provider' => null,
                'receipt_url' => null,
                'payment_meta' => [
                    'res_cd' => $pgResCd,
                    'use_pay_method' => $validated['use_pay_method'] ?? $pgResponse['use_pay_method'] ?? null,
                    'app_time' => $pgResponse['app_time'] ?? null,
                    'account' => $pgResponse['account'] ?? null,
                    'bank_name' => $pgResponse['bank_name'] ?? null,
                    'vnbank_expire_date' => $pgResponse['vnbank_expire_date'] ?? null,
                    'pg_raw_response' => $pgResponse,
                ],
                'payment_device' => $this->detectDevice($request),
            ], $approvedAmt);

            return redirect($this->resolveSuccessUrl($ordrIdxx));

        } catch (PaymentAmountMismatchException $e) {
            Log::error('KCP: amount mismatch', [
                'ordr_idxx' => $ordrIdxx,
                'expected' => $e->getExpectedAmount(),
                'actual' => $e->getActualAmount(),
            ]);

            return redirect($this->resolveFailUrl(['error' => 'amount_mismatch', 'orderId' => $ordrIdxx]));

        } catch (\Exception $e) {
            Log::error('KCP: confirm exception', [
                'ordr_idxx' => $ordrIdxx,
                'error' => $e->getMessage(),
            ]);

            return redirect($this->resolveFailUrl([
                'error' => 'confirm_failed',
                'message' => $e->getMessage(),
                'orderId' => $ordrIdxx,
            ]));
        }
    }

    /**
     * KCP 가상계좌 입금 통보 처리
     *
     * POST /plugins/sirsoft-pay-nhnkcp/payment/vbank-notify
     * (KCP 서버 → 우리 서버, CSRF 제외)
     */
    public function vbankNotify(VbankNotifyRequest $request): Response
    {
        $validated = $request->validated();

        $tno = $validated['tno'];
        $ordrIdxx = $validated['ordr_idxx'];
        $goodMny = (int) $validated['good_mny'];
        $resCd = $validated['res_cd'];

        if ($resCd !== self::SUCCESS_RES_CD) {
            Log::warning('KCP: vbank deposit not confirmed', ['tno' => $tno, 'ordr_idxx' => $ordrIdxx, 'res_cd' => $resCd]);

            return response('OK', 200)->header('Content-Type', 'text/plain');
        }

        try {
            $order = $this->orderService->findByOrderNumber($ordrIdxx);

            if (! $order) {
                Log::error('KCP: vbank notify - order not found', ['ordr_idxx' => $ordrIdxx, 'tno' => $tno]);

                return response('FAIL', 200)->header('Content-Type', 'text/plain');
            }

            $this->orderService->completePayment($order, [
                'transaction_id' => $tno,
                'payment_meta' => [
                    'res_cd' => $resCd,
                    'bank_name' => $validated['bank_name'] ?? null,
                    'account' => $validated['account'] ?? null,
                    'account_holder' => $validated['account_holder'] ?? null,
                    'vnbank_expire_date' => $validated['vnbank_expire_date'] ?? null,
                    'pg_raw_response' => $validated,
                ],
            ], $goodMny);

            Log::info('KCP: vbank deposit confirmed', ['tno' => $tno, 'ordr_idxx' => $ordrIdxx, 'good_mny' => $goodMny]);

            return response('OK', 200)->header('Content-Type', 'text/plain');

        } catch (\Exception $e) {
            Log::error('KCP: vbank notify failed', [
                'tno' => $tno,
                'ordr_idxx' => $ordrIdxx,
                'error' => $e->getMessage(),
            ]);

            return response('FAIL', 200)->header('Content-Type', 'text/plain');
        }
    }

    /**
     * 가상계좌 발급 처리
     *
     * authCallback에서 use_pay_method=VCNT일 때 호출됩니다.
     * CLI로 계좌 정보를 확인한 뒤 order_meta에 저장하고 성공 페이지로 이동합니다.
     * 실제 결제 완료(completePayment)는 입금 통보(vbankNotify) 시점에 처리됩니다.
     */
    private function handleVbankIssued(
        array $validated,
        Order $order,
        string $encData,
        string $encInfo,
        string $ordrIdxx,
        string $custIp,
    ): \Illuminate\Http\RedirectResponse {
        try {
            $pgResponse = $this->apiService->approvePayment($encData, $encInfo, $ordrIdxx, $custIp);
            $pgResCd = $pgResponse['res_cd'] ?? '';

            if ($pgResCd !== self::SUCCESS_RES_CD) {
                Log::warning('KCP: vbank account issuance failed', [
                    'ordr_idxx' => $ordrIdxx,
                    'res_cd' => $pgResCd,
                    'res_msg' => $pgResponse['res_msg'] ?? '',
                ]);

                return redirect($this->resolveFailUrl([
                    'error' => $pgResCd,
                    'message' => $pgResponse['res_msg'] ?? '',
                    'orderId' => $ordrIdxx,
                ]));
            }

            $tno = $pgResponse['tno'] ?? ($validated['tno'] ?? '');

            // 가상계좌 발급 정보를 order_meta에 저장 (주문은 PENDING_PAYMENT 상태 유지)
            $existingMeta = $order->order_meta ?? [];
            $order->update([
                'order_meta' => array_merge($existingMeta, [
                    'vbank_tno' => $tno,
                    'vbank_account' => $pgResponse['account'] ?? null,
                    'vbank_bank_name' => $pgResponse['bank_name'] ?? null,
                    'vbank_expire_date' => $pgResponse['vnbank_expire_date'] ?? null,
                    'vbank_issued_at' => now()->toIso8601String(),
                    'pg_raw_response' => $pgResponse,
                ]),
            ]);

            Log::info('KCP: vbank account issued', [
                'ordr_idxx' => $ordrIdxx,
                'tno' => $tno,
                'bank_name' => $pgResponse['bank_name'] ?? null,
                'account' => $pgResponse['account'] ?? null,
            ]);

            return redirect($this->resolveSuccessUrl($ordrIdxx));

        } catch (\Exception $e) {
            Log::error('KCP: vbank issuance exception', [
                'ordr_idxx' => $ordrIdxx,
                'error' => $e->getMessage(),
            ]);

            return redirect($this->resolveFailUrl([
                'error' => 'vbank_issuance_failed',
                'message' => $e->getMessage(),
                'orderId' => $ordrIdxx,
            ]));
        }
    }

    private function resolveSuccessUrl(string $orderId): string
    {
        $settings = $this->pluginSettingsService->get(self::PLUGIN_IDENTIFIER) ?? [];
        $urlTemplate = $settings['redirect_success_url'] ?? '/shop/orders/{orderId}/complete';

        return str_replace('{orderId}', $orderId, $urlTemplate);
    }

    private function resolveFailUrl(array $queryParams = []): string
    {
        $settings = $this->pluginSettingsService->get(self::PLUGIN_IDENTIFIER) ?? [];
        $baseUrl = $settings['redirect_fail_url'] ?? '/shop/checkout';

        if (empty($queryParams)) {
            return $baseUrl;
        }

        $query = http_build_query(array_filter($queryParams));
        $separator = str_contains($baseUrl, '?') ? '&' : '?';

        return $baseUrl . $separator . $query;
    }

    private function detectDevice(Request $request): string
    {
        $userAgent = $request->userAgent() ?? '';
        $mobileKeywords = ['Mobile', 'Android', 'iPhone', 'iPad', 'iPod'];

        foreach ($mobileKeywords as $keyword) {
            if (stripos($userAgent, $keyword) !== false) {
                return 'mobile';
            }
        }

        return 'pc';
    }
}
