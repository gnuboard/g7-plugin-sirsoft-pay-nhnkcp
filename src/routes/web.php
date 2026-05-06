<?php

use Illuminate\Support\Facades\Route;
use Plugins\Sirsoft\Pay\Nhnkcp\Controllers\PaymentCallbackController;

/*
|--------------------------------------------------------------------------
| NHN KCP Plugin Web Routes
|--------------------------------------------------------------------------
|
| 프리픽스: /plugins/sirsoft-pay-nhnkcp (PluginRouteServiceProvider 자동 적용)
| 미들웨어: web (PluginRouteServiceProvider 자동 적용)
|
| KCP Standard Pay는 브라우저 POST 콜백 방식이므로 CSRF 미들웨어를 제외합니다.
|
*/

Route::withoutMiddleware([\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class])->group(function () {
    // 결제 승인 콜백 (KCP → 브라우저 POST)
    Route::post('/payment/callback', [PaymentCallbackController::class, 'authCallback'])
        ->name('payment.callback');

    // 가상계좌 입금 통보 (KCP 서버 → 우리 서버 POST)
    Route::post('/payment/vbank-notify', [PaymentCallbackController::class, 'vbankNotify'])
        ->name('payment.vbank-notify');
});
