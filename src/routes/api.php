<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| NHN KCP Plugin API Routes
|--------------------------------------------------------------------------
|
| 프리픽스: /api/plugins/sirsoft-pay-nhnkcp (PluginRouteServiceProvider 자동 적용)
| 미들웨어: api (PluginRouteServiceProvider 자동 적용)
|
*/

Route::prefix('admin')->middleware(['auth:sanctum', 'admin'])->group(function () {
    Route::get('/vbank-notify-url', function () {
        return response()->json([
            'success' => true,
            'data' => [
                'url' => url('/plugins/sirsoft-pay-nhnkcp/payment/vbank-notify'),
            ],
        ]);
    })->name('vbank.notify.url');
});
