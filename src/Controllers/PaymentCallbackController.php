<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNhnkcp\Controllers;

use App\Extension\HookManager;
use App\Services\PluginSettingsService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Exceptions\PaymentAmountMismatchException;
use Carbon\Carbon;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Services\OrderProcessingService;
use Plugins\Sirsoft\PayNhnkcp\Http\Requests\AuthCallbackRequest;
use Plugins\Sirsoft\PayNhnkcp\Http\Requests\VbankNotifyRequest;
use Plugins\Sirsoft\PayNhnkcp\Services\NhnKcpApiService;

/**
 * KCP 결제 콜백 컨트롤러
 *
 * KCP Standard Pay는 브라우저 POST 콜백 방식입니다:
 *   1단계: 브라우저가 POST 콜백으로 enc_data, enc_info 등 전달 → authCallback()
 *   2단계: 서버가 KCP CLI 바이너리로 최종 승인 확인 → NhnKcpApiService::approvePayment()
 */
class PaymentCallbackController
{
    private const PLUGIN_IDENTIFIER = 'sirsoft-pay_nhnkcp';

    private const SUCCESS_RES_CD = '0000';

    // 사용자가 결제창을 직접 닫은 취소 코드 — 조용히 체크아웃으로 복귀
    private const CANCEL_RES_CODES = ['3001', '3000', '7777', ''];

    public function __construct(
        private readonly OrderProcessingService $orderService,
        private readonly PluginSettingsService $pluginSettingsService,
        private readonly NhnKcpApiService $apiService,
    ) {}

    /**
     * KCP 결제 승인 콜백
     *
     * POST /plugins/sirsoft-pay_nhnkcp/payment/callback
     * (CSRF 제외 - KCP가 브라우저 통해 POST 전달)
     */
    /**
     * KCP 결제 승인 콜백
     *
     * 1단계: KCP Standard Pay 가 결제창 완료 후 ReturnURL 로 POST 콜백.
     * 2단계: enc_data + enc_info 로 서버 승인 요청. 결제 수단별로 가상계좌(계좌발급)/
     * 카드(즉시완료) 분기. 사용자 취소 / 인증 실패 시 에러 query 로 체크아웃 복귀.
     *
     * @param  AuthCallbackRequest  $request  검증된 콜백 페이로드
     * @return \Illuminate\Http\RedirectResponse 성공/실패 URL 로 리다이렉트
     */
    public function authCallback(AuthCallbackRequest $request): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validated();

        $resCd = $validated['res_cd'];
        $resMsg = $validated['res_msg'] ?? '';
        $encData = $validated['enc_data'] ?? '';  // 모바일 취소 시 미포함
        $encInfo = $validated['enc_info'] ?? '';  // 모바일 취소 시 미포함
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
            $isCancelled = in_array($resCd, self::CANCEL_RES_CODES, true);

            Log::info('KCP: payment result non-success', [
                'ordr_idxx' => $ordrIdxx,
                'res_cd' => $resCd,
                'res_msg' => $resMsg,
                'is_cancelled' => $isCancelled,
            ]);

            // 사용자 취소는 오류 없이 체크아웃으로 복귀
            if ($isCancelled) {
                return redirect($this->resolveFailUrl());
            }

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
            // KCP 콜백의 use_pay_method=VCNT 또는 주문의 payment_method=vbank 로 감지
            $isVbank = ($validated['use_pay_method'] ?? '') === 'VCNT'
                || in_array($order->payment?->payment_method?->value, ['vbank', 'virtual_account'], true);
            if ($isVbank) {
                return $this->handleVbankIssued($validated, $order, $encData, $encInfo, $ordrIdxx, $custIp);
            }

            HookManager::doAction('sirsoft-pay_nhnkcp.payment.before_confirm', $order, $validated);

            // 3단계: KCP CLI로 최종 승인 확인
            $pgResponse = $this->apiService->approvePayment($encData, $encInfo, $ordrIdxx, $custIp);

            HookManager::doAction('sirsoft-pay_nhnkcp.payment.after_confirm', $order, $pgResponse);

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
     * POST /plugins/sirsoft-pay_nhnkcp/payment/vbank-notify
     * (KCP 서버 → 우리 서버, CSRF 제외)
     */
    /**
     * 가상계좌 입금 통보 처리
     *
     * KCP 서버가 직접 호출하는 입금 확인 웹훅. res_cd '0000' 입금완료 통보만
     * 결제완료 처리하고 그 외 코드는 payment_meta 에 timeline 으로 누적.
     * 어떤 결과든 200 + 정확히 "OK" 응답으로 KCP 재시도 차단.
     *
     * @param  VbankNotifyRequest  $request  검증된 입금통보 페이로드
     * @return Response 항상 200 + "OK" (text/plain)
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
        // KCP 브라우저 콜백 res_cd=0000은 계좌 발급 성공을 의미.
        // CLI 호출로 복호화된 계좌 정보를 가져오되, CLI 실패는 계좌 상세정보 조회 실패일 뿐
        // 계좌 발급 자체는 성공이므로 success URL로 리다이렉트한다.
        $tno = $validated['tno'] ?? '';
        $pgResponse = [];

        try {
            $pgResponse = $this->apiService->approvePayment($encData, $encInfo, $ordrIdxx, $custIp);
            $pgResCd = $pgResponse['res_cd'] ?? '';

            if ($pgResCd === self::SUCCESS_RES_CD) {
                $tno = $pgResponse['tno'] ?? $tno;
                Log::info('KCP: vbank account issued via CLI', [
                    'ordr_idxx' => $ordrIdxx,
                    'tno' => $tno,
                    'bank_name' => $pgResponse['bank_name'] ?? null,
                    'account' => $pgResponse['account'] ?? null,
                ]);
            } else {
                Log::warning('KCP: vbank CLI returned non-0000 — account still issued, proceeding to success', [
                    'ordr_idxx' => $ordrIdxx,
                    'res_cd' => $pgResCd,
                    'res_msg' => $pgResponse['res_msg'] ?? '',
                ]);
            }
        } catch (\Exception $e) {
            Log::warning('KCP: vbank CLI exception — account still issued, proceeding to success', [
                'ordr_idxx' => $ordrIdxx,
                'error' => $e->getMessage(),
            ]);
        }

        // 가상계좌 발급 정보를 OrderPayment vbank 전용 컬럼에 저장 (PENDING_PAYMENT 상태 유지)
        try {
            $expireRaw = $pgResponse['va_date'] ?? null;
            $vbankDueAt = null;
            if ($expireRaw) {
                // KCP va_date: YYYYMMDDHHMMSS (14자리)
                try {
                    $vbankDueAt = strlen($expireRaw) <= 8
                        ? Carbon::createFromFormat('Ymd', $expireRaw)->endOfDay()
                        : Carbon::createFromFormat('YmdHis', $expireRaw);
                } catch (\Exception) {
                    $vbankDueAt = null;
                }
            }

            $order->payment()->update(array_filter([
                'transaction_id'  => $tno ?: null,
                'vbank_name'      => $pgResponse['bankname'] ?? null,
                'vbank_number'    => $pgResponse['account'] ?? null,
                'vbank_holder'    => $pgResponse['depositor'] ?? null,
                'vbank_due_at'    => $vbankDueAt,
                'vbank_issued_at' => now(),
                'payment_meta'    => $pgResponse ?: null,
            ], fn ($v) => $v !== null));
        } catch (\Exception $e) {
            Log::error('KCP: failed to save vbank info to OrderPayment', [
                'ordr_idxx' => $ordrIdxx,
                'error' => $e->getMessage(),
            ]);
        }

        return redirect($this->resolveSuccessUrl($ordrIdxx));
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
