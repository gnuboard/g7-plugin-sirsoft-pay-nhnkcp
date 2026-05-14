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
use Modules\Sirsoft\Ecommerce\Helpers\DeviceDetector;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Services\OrderProcessingService;
use Plugins\Sirsoft\PayNhnkcp\Concerns\SendsKcpNotifyResponse;
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
    use SendsKcpNotifyResponse;

    private const PLUGIN_IDENTIFIER = 'sirsoft-pay_nhnkcp';

    private const SUCCESS_RES_CD = '0000';

    // 사용자가 결제창을 직접 닫은 취소 코드 — 조용히 체크아웃으로 복귀
    private const CANCEL_RES_CODES = ['3001', '3000', '7777', ''];

    // KCP 공통통보 tx_cd — 그누보드5 settle_kcp_common.php 참고
    private const KCP_TX_CD_VBANK_DEPOSIT = 'TX00';

    // KCP 가상계좌 입금통보 op_cd
    private const KCP_OP_CD_DEPOSIT_COMPLETE = '50';

    private const KCP_OP_CD_DEPOSIT_RESEND = '01';

    private const KCP_OP_CD_DEPOSIT_CANCEL = '13';

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
        $resMsg = $this->decodeKcpMessage($validated['res_msg'] ?? '');
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
                return $this->handleVbankIssued($validated, $order, $encData, $encInfo, $ordrIdxx, $custIp, $request);
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

            $isEscrow = ($pgResponse['escw_yn'] ?? '') === 'Y';

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
                    'escw_yn' => $pgResponse['escw_yn'] ?? null,
                    'pg_raw_response' => $pgResponse,
                ],
                'payment_device' => DeviceDetector::detect($request),
            ], $approvedAmt);

            // 에스크로 결제인 경우 is_escrow 플래그 저장
            if ($isEscrow) {
                $order->payment()->update(['is_escrow' => true]);
            }

            // KCP가 실제로 결제를 처리했으므로 pg_provider를 nhnkcp로 보정
            // (기본 PG가 타 PG일 때 주문이 해당 PG provider로 생성되는 경우 대비)
            $order->payment()->update(['pg_provider' => 'nhnkcp']);

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
     *
     * KCP 공식 webhook 페이로드 (그누보드5 settle_kcp_common.php 참고):
     *   tx_cd=TX00 (가상계좌 입금) + op_cd 50/01/13 + ipgm_mnyx + order_no + noti_id
     *
     * 응답: <form><input name="result" value="0000"> HTML 을 KCP 가 파싱.
     * result="0000" 이면 통보 성공, 그 외이면 재통보 (최대 10회).
     *
     * @param  VbankNotifyRequest  $request  검증된 입금통보 페이로드
     * @return Response 200 + KCP 표준 result HTML
     */
    public function vbankNotify(VbankNotifyRequest $request): Response
    {
        $validated = $request->validated();

        $txCd = (string) ($validated['tx_cd'] ?? self::KCP_TX_CD_VBANK_DEPOSIT);
        $tno = $validated['tno'];
        $orderNo = $validated['order_no'];
        $opCd = (string) ($validated['op_cd'] ?? '');
        $ipgmMny = (int) ($validated['ipgm_mnyx'] ?? 0);
        $notiId = $validated['noti_id'] ?? null;

        // tx_cd 가 TX00 가 아니면 본 endpoint 가 처리하지 않음.
        // (KCP 가 단일 webhook URL 에 모든 tx_cd 를 보낼 수도 있어 안전하게 무시 + result=0000)
        if ($txCd !== self::KCP_TX_CD_VBANK_DEPOSIT) {
            Log::info('KCP: vbank-notify ignored non-TX00', [
                'tno' => $tno, 'order_no' => $orderNo, 'tx_cd' => $txCd, 'noti_id' => $notiId,
            ]);

            return $this->kcpNotifyResponse();
        }

        // op_cd 분기 (그누보드5 settle_kcp_common.php 와 동일 정책):
        //   13 (망취소)         → 별도 처리 (입금완료 안 함, 결제 취소 정책 미정 — 로깅만)
        //   그 외 (50/01/18/…)  → 입금완료 처리 (KCP testadmin 모의입금은 op_cd=18 사용 확인됨)
        // KCP 공식 문서가 50/01/13 만 언급하지만 실제 KCP testadmin 발신은 18 등 추가 코드를
        // 사용하므로 그누보드5 의 op_cd 무관 입금처리 정책을 따른다 (망취소만 분리).
        if ($opCd === self::KCP_OP_CD_DEPOSIT_CANCEL) {
            Log::warning('KCP: vbank deposit cancelled (op_cd=13)', [
                'tno' => $tno, 'order_no' => $orderNo, 'noti_id' => $notiId, 'ipgm_mnyx' => $ipgmMny,
            ]);

            return $this->kcpNotifyResponse();
        }

        try {
            $order = $this->orderService->findByOrderNumber($orderNo);

            if (! $order) {
                Log::error('KCP: vbank notify - order not found', [
                    'order_no' => $orderNo, 'tno' => $tno, 'noti_id' => $notiId,
                ]);

                // 영구 실패 — 재시도 의미 없음. result=0000 으로 KCP 재통보 차단.
                return $this->kcpNotifyResponse();
            }

            // 멱등성 — 이미 결제완료 상태면 재처리 없이 0000 응답 (op_cd=01 재전송 대응)
            if ($order->payment?->isPaid() ?? false) {
                Log::info('KCP: vbank-notify already paid (idempotent)', [
                    'tno' => $tno, 'order_no' => $orderNo, 'noti_id' => $notiId, 'op_cd' => $opCd,
                ]);

                return $this->kcpNotifyResponse();
            }

            $this->orderService->completePayment($order, [
                'transaction_id' => $tno,
                'payment_meta' => [
                    'tx_cd' => $txCd,
                    'op_cd' => $opCd,
                    'noti_id' => $notiId,
                    'bank_code' => $validated['bank_code'] ?? null,
                    'account' => $validated['account'] ?? null,
                    'ipgm_name' => $validated['ipgm_name'] ?? null,
                    'remitter' => $validated['remitter'] ?? null,
                    'ipgm_time' => $validated['tx_tm'] ?? null,
                    'pg_raw_response' => $validated,
                ],
            ], $ipgmMny);

            $order->payment()->update(['pg_provider' => 'nhnkcp']);

            Log::info('KCP: vbank deposit confirmed', [
                'tno' => $tno, 'order_no' => $orderNo, 'ipgm_mnyx' => $ipgmMny,
                'op_cd' => $opCd, 'noti_id' => $notiId,
            ]);

            return $this->kcpNotifyResponse();

        } catch (\Exception $e) {
            Log::error('KCP: vbank notify failed', [
                'tno' => $tno, 'order_no' => $orderNo, 'noti_id' => $notiId,
                'error' => $e->getMessage(),
            ]);

            // 일시적 실패 (DB 등) — result != 0000 으로 KCP 재통보 유도
            return $this->kcpNotifyRetry();
        }
    }

    /**
     * 가상계좌 발급 처리
     *
     * authCallback 에서 use_pay_method=VCNT 일 때 호출.
     *
     * PC Standard Pay: enc_data/enc_info 가 동봉되어 CLI 로 복호화하여 계좌 정보를 얻음.
     * Mobile SmartPhone Pay: enc_data 없이 평문 필드(bankname/account/depositor/va_date)가
     *   콜백 POST 에 직접 포함되므로 CLI 호출을 건너뛰고 요청에서 직접 읽음.
     *
     * 발급 성공 = res_cd == 0000 AND bankname/account 가 모두 채워진 경우만.
     * 그 외 (CLI 9502 / 예외 / 평문 필드 결락) 는 발급 실패로 판정해 failPayment + fail URL.
     */
    private function handleVbankIssued(
        array $validated,
        Order $order,
        string $encData,
        string $encInfo,
        string $ordrIdxx,
        string $custIp,
        Request $request,
    ): \Illuminate\Http\RedirectResponse {
        $tno = $validated['tno'] ?? '';
        $pgResponse = [];
        $isMobile = $encData === '' || $encInfo === '';

        if ($isMobile) {
            // 모바일: 콜백 POST 의 평문 필드를 그대로 응답으로 취급
            // KCP 변종 키 모두 대응 (bankname|bank_name, depositor|account_holder, va_date|vnbank_expire_date)
            $pgResponse = [
                'res_cd'    => $validated['res_cd'] ?? self::SUCCESS_RES_CD,
                'res_msg'   => $validated['res_msg'] ?? '',
                'tno'       => $tno,
                'bankname'  => $request->input('bankname') ?? $request->input('bank_name'),
                'account'   => $request->input('account'),
                'depositor' => $request->input('depositor') ?? $request->input('account_holder'),
                'va_date'   => $request->input('va_date') ?? $request->input('vnbank_expire_date'),
            ];
        } else {
            // PC: CLI 호출. 호출 자체 실패(권한 누락 / pub.key 부재 등)는 발급 여부 미확정이므로 안전하게 실패 처리.
            try {
                $pgResponse = $this->apiService->approvePayment($encData, $encInfo, $ordrIdxx, $custIp);
            } catch (\Exception $e) {
                Log::error('KCP: vbank CLI exception — treating as issuance failure', [
                    'ordr_idxx' => $ordrIdxx,
                    'error' => $e->getMessage(),
                ]);

                $this->orderService->failPayment($order, 'cli_exception', $e->getMessage());

                return redirect($this->resolveFailUrl([
                    'error' => 'cli_exception',
                    'message' => $e->getMessage(),
                    'orderId' => $ordrIdxx,
                ]));
            }
        }

        $pgResCd = $pgResponse['res_cd'] ?? '';
        $bankname = $pgResponse['bankname'] ?? null;
        $account = $pgResponse['account'] ?? null;

        // 발급 성공 판정 — 핵심 필드(은행명/계좌번호) 모두 채워진 경우만 성공.
        // KCP 표준결제창은 결제수단별로 정상 res_cd 가 다르다 (card=0000, vbank=V000).
        // res_cd 단일 비교 대신 발급 데이터 존재 여부로 판정해 응답 변종에 견고하게 대응.
        // - V000 + bankname/account 존재 → 정상 발급 (success)
        // - 0000 + bankname/account 존재 → 정상 발급 (success)
        // - 9502 + bankname/account 부재 → 발급 실패 (fail)
        $hasIssuanceData = ($bankname !== null && $bankname !== '')
            && ($account !== null && $account !== '');

        if (! $hasIssuanceData) {
            $resMsg = $this->decodeKcpMessage($pgResponse['res_msg'] ?? '');
            $effectiveCode = $pgResCd !== '' && $pgResCd !== self::SUCCESS_RES_CD
                ? $pgResCd
                : 'vbank_issuance_incomplete';
            $effectiveMsg = $resMsg !== '' ? $resMsg : '가상계좌 발급 정보 누락';

            Log::warning('KCP: vbank issuance failed', [
                'ordr_idxx' => $ordrIdxx,
                'res_cd' => $pgResCd,
                'res_msg' => $resMsg,
                'has_bankname' => $bankname !== null && $bankname !== '',
                'has_account' => $account !== null && $account !== '',
                'is_mobile' => $isMobile,
            ]);

            $this->orderService->failPayment($order, $effectiveCode, $effectiveMsg);

            return redirect($this->resolveFailUrl([
                'error' => $effectiveCode,
                'message' => $effectiveMsg,
                'orderId' => $ordrIdxx,
            ]));
        }

        if ($isMobile) {
            Log::info('KCP: vbank account issued via mobile callback (no CLI)', [
                'ordr_idxx' => $ordrIdxx,
                'tno'       => $tno,
                'bankname'  => $bankname,
                'account'   => $account,
                'va_date'   => $pgResponse['va_date'] ?? null,
            ]);
        } else {
            $tno = $pgResponse['tno'] ?? $tno;
            Log::info('KCP: vbank account issued via CLI', [
                'ordr_idxx' => $ordrIdxx,
                'tno' => $tno,
                'bankname' => $bankname,
                'account' => $account,
            ]);
        }

        // 가상계좌 발급 정보를 OrderPayment vbank 전용 컬럼에 저장 (PENDING_PAYMENT 상태 유지).
        // 저장 실패 시도 부분 저장으로 사용자에게 잘못된 정보가 노출되지 않도록 failPayment 처리.
        try {
            $expireRaw = $pgResponse['va_date'] ?? null;
            $vbankDueAt = null;
            if ($expireRaw) {
                // KCP va_date: YYYYMMDDHHMMSS (14자리) 또는 YYYYMMDD (8자리)
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
                'vbank_name'      => $bankname,
                'vbank_number'    => $account,
                'vbank_holder'    => $pgResponse['depositor'] ?? null,
                'vbank_due_at'    => $vbankDueAt,
                'vbank_issued_at' => now(),
                'payment_device'  => $isMobile ? 'mobile' : 'pc',
                'payment_meta'    => $pgResponse,
            ], fn ($v) => $v !== null));
        } catch (\Exception $e) {
            Log::error('KCP: failed to save vbank info to OrderPayment', [
                'ordr_idxx' => $ordrIdxx,
                'error' => $e->getMessage(),
            ]);

            $this->orderService->failPayment($order, 'vbank_save_failed', $e->getMessage());

            return redirect($this->resolveFailUrl([
                'error' => 'vbank_save_failed',
                'message' => $e->getMessage(),
                'orderId' => $ordrIdxx,
            ]));
        }

        return redirect($this->resolveSuccessUrl($ordrIdxx));
    }

    private function resolveSuccessUrl(string $orderId): string
    {
        $settings = $this->pluginSettingsService->get(self::PLUGIN_IDENTIFIER) ?? [];
        $urlTemplate = $settings['redirect_success_url'] ?? '/shop/orders/{orderId}/complete';

        return $this->absolutize(str_replace('{orderId}', $orderId, $urlTemplate));
    }

    private function resolveFailUrl(array $queryParams = []): string
    {
        $settings = $this->pluginSettingsService->get(self::PLUGIN_IDENTIFIER) ?? [];
        $baseUrl = $this->absolutize($settings['redirect_fail_url'] ?? '/shop/checkout');

        if (empty($queryParams)) {
            return $baseUrl;
        }

        // error 코드와 message 가 모두 있으면 message 앞에 '[코드] ' 자동 prefix.
        // 체크아웃 페이지에서 사용자가 오류 종류를 즉시 식별할 수 있도록 한다.
        // 예: error=9502, message=연동 모듈 호출 오류 → message=[9502] 연동 모듈 호출 오류
        if (! empty($queryParams['error']) && ! empty($queryParams['message'])) {
            $queryParams['message'] = sprintf('[%s] %s', $queryParams['error'], $queryParams['message']);
        }

        $query = http_build_query(array_filter($queryParams));
        $separator = str_contains($baseUrl, '?') ? '&' : '?';

        return $baseUrl . $separator . $query;
    }

    /**
     * 상대 경로면 APP_URL 기준으로 절대 URL 화.
     *
     * PG가 브라우저 POST 로 콜백을 보내는 동안 Apache 가 ProxyPreserveHost Off 등
     * 으로 Host 헤더를 localhost 로 바꿔서 PHP 에 전달하는 경우, Laravel 의
     * redirect('/path') 가 http://localhost/path 를 생성해버린다. config('app.url')
     * (.env 의 APP_URL)을 명시적 base 로 사용하여 도메인을 보존한다.
     */
    private function absolutize(string $url): string
    {
        if (preg_match('#^https?://#i', $url) === 1) {
            return $url;
        }

        $base = rtrim((string) config('app.url'), '/');
        $path = $url === '' ? '/' : ($url[0] === '/' ? $url : '/' . $url);

        return $base . $path;
    }

    /**
     * KCP 모바일 게이트웨이는 EUC-KR(CP949) 인코딩으로 res_msg를 POST.
     * 유효한 UTF-8이 아니면 CP949로 간주하고 변환한다.
     */
    private function decodeKcpMessage(string $msg): string
    {
        if ($msg === '' || mb_check_encoding($msg, 'UTF-8')) {
            return $msg;
        }

        $converted = mb_convert_encoding($msg, 'UTF-8', 'CP949');

        return $converted !== false ? $converted : $msg;
    }

}
