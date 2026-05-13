<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNhnkcp\Controllers;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use App\Services\PluginSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class AdminOrderListController extends AdminBaseController
{
    private const PLUGIN_IDENTIFIER = 'sirsoft-pay_nhnkcp';

    private bool $isTest;

    public function __construct(PluginSettingsService $pluginSettingsService)
    {
        parent::__construct();
        $settings = $pluginSettingsService->get(self::PLUGIN_IDENTIFIER) ?? [];
        $this->isTest = $settings['is_test_mode'] ?? true;
    }

    /**
     * NHN KCP 테스트 모드 주문 맵 반환
     *
     * 현재 플러그인이 테스트 모드이면 최근 6개월 nhnkcp 전체 주문을 테스트로 간주한다.
     * 라이브 모드이면 payment_meta.is_test_mode = true 인 주문만 반환한다.
     *
     * @return JsonResponse 테스트 모드 주문 맵 { "order_number": true, ... }
     */
    public function testModeMap(): JsonResponse
    {
        $query = DB::table('ecommerce_orders as o')
            ->join('ecommerce_order_payments as p', 'p.order_id', '=', 'o.id')
            ->where('p.pg_provider', 'nhnkcp')
            ->where('p.created_at', '>=', now()->subMonths(6));

        if ($this->isTest) {
            $orderNumbers = $query->pluck('o.order_number');
            $map = array_fill_keys($orderNumbers->all(), true);
        } else {
            $rows = $query
                ->whereNotNull('p.payment_meta')
                ->select(['o.order_number', 'p.payment_meta'])
                ->get();

            $map = [];
            foreach ($rows as $row) {
                $meta = json_decode($row->payment_meta, true) ?? [];
                if ($meta['is_test_mode'] ?? false) {
                    $map[$row->order_number] = true;
                }
            }
        }

        return ResponseHelper::success('messages.success', $map);
    }
}
