<?php

use Illuminate\Support\Facades\Route;
use Plugins\Sirsoft\PayNhnkcp\Controllers\AdminTransactionController;
use Plugins\Sirsoft\PayNhnkcp\Controllers\MobileApprovalController;
use Plugins\Sirsoft\PayNhnkcp\Controllers\UserReceiptController;

/*
|--------------------------------------------------------------------------
| NHN KCP Plugin API Routes
|--------------------------------------------------------------------------
|
| 프리픽스: /api/plugins/sirsoft-pay_nhnkcp (PluginRouteServiceProvider 자동 적용)
| 미들웨어: api (PluginRouteServiceProvider 자동 적용)
|
*/

Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('/user/orders/{orderNumber}/receipt', [UserReceiptController::class, 'show'])
        ->name('user.orders.receipt');

    // 모바일 결제 승인키 획득 (KCP SmartPhone Pay SOAP)
    Route::post('/mobile/approval-key', [MobileApprovalController::class, 'getApprovalKey'])
        ->name('mobile.approval-key');
});

Route::prefix('admin')->name('admin.')->middleware(['auth:sanctum', 'admin'])->group(function () {
    // 가상계좌 입금통보 URL 조회 (관리자 설정 페이지 표시용)
    Route::get('/vbank-notify-url', function () {
        return response()->json([
            'success' => true,
            'data' => [
                'url' => url('/plugins/sirsoft-pay_nhnkcp/payment/vbank-notify'),
            ],
        ]);
    })->name('vbank.notify.url');

    // 주문번호로 거래 정보 조회 (레이아웃 확장 자동 로드용)
    Route::get('/orders/{orderNumber}/transaction-status', [AdminTransactionController::class, 'queryByOrder'])
        ->name('orders.transaction-status');
});
