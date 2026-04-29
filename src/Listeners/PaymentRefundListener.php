<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\Pay\Nhnkcp\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Plugins\Sirsoft\Pay\Nhnkcp\Services\NhnKcpApiService;

class PaymentRefundListener implements HookListenerInterface
{
    private const PG_PROVIDER_ID = 'nhnkcp';

    public static function getSubscribedHooks(): array
    {
        return [
            'sirsoft-ecommerce.payment.refund' => [
                'method' => 'processRefund',
                'type' => 'filter',
                'priority' => 10,
            ],
        ];
    }

    public function handle(...$args): void {}

    public function processRefund(
        array $result,
        Order $order,
        OrderPayment $payment,
        float $refundAmount,
        ?string $reason = null,
    ): array {
        if ($payment->pg_provider !== self::PG_PROVIDER_ID) {
            return $result;
        }

        $tno = $payment->transaction_id;
        if (! $tno) {
            return [
                'success' => false,
                'error_code' => 'MISSING_TNO',
                'error_message' => __('sirsoft-pay-nhnkcp::messages.refund.missing_tid'),
                'transaction_id' => null,
            ];
        }

        try {
            $apiService = app(NhnKcpApiService::class);

            $cancelMsg = $reason ?? __('sirsoft-pay-nhnkcp::messages.refund.default_reason');
            $cancelAmt = (int) $refundAmount;
            $ordrIdxx = (string) $order->order_number;

            $totalAmt = (int) $payment->amount;
            $isPartial = $cancelAmt < $totalAmt;
            $response = $apiService->cancelPayment($tno, $ordrIdxx, $cancelAmt, $cancelMsg, $isPartial, $totalAmt);

            Log::info('KCP: refund success', [
                'order_id' => $order->id,
                'tno' => $tno,
                'cancel_amt' => $cancelAmt,
            ]);

            return [
                'success' => true,
                'error_code' => null,
                'error_message' => null,
                'transaction_id' => $response['tno'] ?? $tno,
            ];
        } catch (\Exception $e) {
            Log::error('KCP: refund failed', [
                'order_id' => $order->id,
                'tno' => $tno,
                'cancel_amt' => (int) $refundAmount,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error_code' => 'PG_API_ERROR',
                'error_message' => $e->getMessage(),
                'transaction_id' => null,
            ];
        }
    }
}
