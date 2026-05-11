<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNhnkcp\Controllers;

use App\Extension\HookManager;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Services\OrderProcessingService;
use Plugins\Sirsoft\PayNhnkcp\Http\Requests\EscrowCommonNotifyRequest;

/**
 * KCP 에스크로 공통통보 컨트롤러
 *
 * POST /plugins/sirsoft-pay_nhnkcp/payment/escrow-common-notify
 * KCP 가맹점 어드민에서 공통통보 URL로 등록해야 합니다.
 *
 * tx_cd 별 처리:
 *   TX02 + cl_status=2 → 구매확인
 *   TX02 + cl_status=8 → 구매취소
 *   TX02 + cl_status=3 → 구매취소 확인
 *   TX03               → 배송시작 통보
 */
class EscrowCommonNotifyController
{
    public function __construct(
        private readonly OrderProcessingService $orderService,
    ) {}

    public function handle(EscrowCommonNotifyRequest $request): Response
    {
        $validated = $request->validated();

        $txCd     = (string) $validated['tx_cd'];
        $tno      = (string) ($validated['tno'] ?? '');
        $ordrIdxx = (string) $validated['ordr_idxx'];
        $clStatus = (string) ($validated['cl_status'] ?? '');

        Log::info('KCP: escrow common notify received', [
            'tx_cd'     => $txCd,
            'tno'       => $tno,
            'ordr_idxx' => $ordrIdxx,
            'cl_status' => $clStatus,
        ]);

        try {
            $order = $this->orderService->findByOrderNumber($ordrIdxx);

            if (! $order) {
                Log::error('KCP: escrow common notify - order not found', ['ordr_idxx' => $ordrIdxx]);

                return $this->ok();
            }

            match ($txCd) {
                'TX02' => match ($clStatus) {
                    '2'  => HookManager::doAction('sirsoft-pay_nhnkcp.escrow.purchase_confirmed', $order, $validated),
                    '8'  => HookManager::doAction('sirsoft-pay_nhnkcp.escrow.purchase_cancelled', $order, $validated),
                    '3'  => HookManager::doAction('sirsoft-pay_nhnkcp.escrow.denial_confirmed', $order, $validated),
                    default => Log::info('KCP: escrow TX02 unknown cl_status', ['cl_status' => $clStatus, 'ordr_idxx' => $ordrIdxx]),
                },
                'TX03' => HookManager::doAction('sirsoft-pay_nhnkcp.escrow.delivery_started', $order, $validated),
                default => Log::info('KCP: escrow common notify - unhandled tx_cd', ['tx_cd' => $txCd, 'ordr_idxx' => $ordrIdxx]),
            };

            Log::info('KCP: escrow common notify processed', [
                'tx_cd'     => $txCd,
                'ordr_idxx' => $ordrIdxx,
                'cl_status' => $clStatus,
            ]);

        } catch (\Exception $e) {
            Log::error('KCP: escrow common notify failed', [
                'tx_cd'     => $txCd,
                'ordr_idxx' => $ordrIdxx,
                'error'     => $e->getMessage(),
            ]);
        }

        return $this->ok();
    }

    private function ok(): Response
    {
        return response('0000', 200)->header('Content-Type', 'text/plain');
    }
}
