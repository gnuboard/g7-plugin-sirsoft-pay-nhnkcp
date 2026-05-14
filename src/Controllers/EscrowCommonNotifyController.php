<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNhnkcp\Controllers;

use App\Extension\HookManager;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Services\OrderProcessingService;
use Plugins\Sirsoft\PayNhnkcp\Concerns\SendsKcpNotifyResponse;
use Plugins\Sirsoft\PayNhnkcp\Http\Requests\EscrowCommonNotifyRequest;

/**
 * KCP 에스크로 공통통보 컨트롤러
 *
 * POST /plugins/sirsoft-pay_nhnkcp/payment/escrow-common-notify
 * KCP 가맹점 어드민에서 공통통보 URL로 등록해야 합니다.
 *
 * tx_cd 별 처리 (그누보드5 settle_kcp_common.php 참고):
 *   TX02 + cl_status=2 → 구매확인
 *   TX02 + cl_status=8 → 구매취소
 *   TX02 + cl_status=3 → 구매취소 확인
 *   TX03               → 배송시작 통보
 *
 * 응답: <form><input name="result" value="0000"> HTML (SendsKcpNotifyResponse trait)
 * IP 가드: RestrictKcpIp 미들웨어 (routes/web.php)
 */
class EscrowCommonNotifyController
{
    use SendsKcpNotifyResponse;

    public function __construct(
        private readonly OrderProcessingService $orderService,
    ) {}

    /**
     * KCP 에스크로 통보 처리 (공통 webhook)
     *
     * @param  EscrowCommonNotifyRequest  $request  검증된 통보 페이로드 (order_no, tx_cd, cl_status 등)
     * @return Response 200 + KCP 표준 result HTML
     */
    public function handle(EscrowCommonNotifyRequest $request): Response
    {
        $validated = $request->validated();

        $txCd     = (string) $validated['tx_cd'];
        $tno      = (string) ($validated['tno'] ?? '');
        $orderNo  = (string) $validated['order_no'];
        $clStatus = (string) ($validated['cl_status'] ?? '');

        Log::info('KCP: escrow common notify received', [
            'tx_cd'    => $txCd,
            'tno'      => $tno,
            'order_no' => $orderNo,
            'cl_status' => $clStatus,
        ]);

        try {
            $order = $this->orderService->findByOrderNumber($orderNo);

            if (! $order) {
                Log::error('KCP: escrow common notify - order not found', ['order_no' => $orderNo]);

                // 영구 실패 — result=0000 으로 재통보 차단
                return $this->kcpNotifyResponse();
            }

            match ($txCd) {
                'TX02' => match ($clStatus) {
                    '2'  => HookManager::doAction('sirsoft-pay_nhnkcp.escrow.purchase_confirmed', $order, $validated),
                    '8'  => HookManager::doAction('sirsoft-pay_nhnkcp.escrow.purchase_cancelled', $order, $validated),
                    '3'  => HookManager::doAction('sirsoft-pay_nhnkcp.escrow.denial_confirmed', $order, $validated),
                    default => Log::info('KCP: escrow TX02 unknown cl_status', ['cl_status' => $clStatus, 'order_no' => $orderNo]),
                },
                'TX03' => HookManager::doAction('sirsoft-pay_nhnkcp.escrow.delivery_started', $order, $validated),
                default => Log::info('KCP: escrow common notify - unhandled tx_cd', ['tx_cd' => $txCd, 'order_no' => $orderNo]),
            };

            Log::info('KCP: escrow common notify processed', [
                'tx_cd'    => $txCd,
                'order_no' => $orderNo,
                'cl_status' => $clStatus,
            ]);

        } catch (\Exception $e) {
            Log::error('KCP: escrow common notify failed', [
                'tx_cd'    => $txCd,
                'order_no' => $orderNo,
                'error'    => $e->getMessage(),
            ]);

            // 일시적 실패 (DB 등) — result != 0000 으로 KCP 재통보 유도
            return $this->kcpNotifyRetry();
        }

        return $this->kcpNotifyResponse();
    }
}
