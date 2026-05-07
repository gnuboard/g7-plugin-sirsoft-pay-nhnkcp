<?php

namespace Plugins\Sirsoft\PayNhnkcp\Tests\Unit\Services;

use App\Services\PluginSettingsService;
use Illuminate\Support\Facades\Http;
use Plugins\Sirsoft\PayNhnkcp\Services\NhnKcpApiService;
use Plugins\Sirsoft\PayNhnkcp\Tests\PluginTestCase;

class NhnKcpApiServiceTest extends PluginTestCase
{
    private const TEST_SITE_CD = 'T0000';

    private const TEST_SITE_KEY = 'TEST_SITE_KEY_0000';

    private function makeService(array $settingsOverrides = []): NhnKcpApiService
    {
        $defaults = [
            'is_test_mode' => true,
            'test_site_cd' => self::TEST_SITE_CD,
            'test_site_key' => self::TEST_SITE_KEY,
            'live_site_cd' => '',
            'live_site_key' => '',
        ];

        $settingsMock = $this->createMock(PluginSettingsService::class);
        $settingsMock->method('get')
            ->willReturn(array_merge($defaults, $settingsOverrides));

        return new NhnKcpApiService($settingsMock);
    }

    public function test_get_site_cd_returns_test_site_cd_in_test_mode(): void
    {
        $service = $this->makeService();

        $this->assertEquals(self::TEST_SITE_CD, $service->getSiteCd());
    }

    public function test_get_site_cd_returns_live_site_cd_in_live_mode(): void
    {
        $service = $this->makeService([
            'is_test_mode' => false,
            'live_site_cd' => 'SR123456',
            'live_site_key' => 'live_site_key_value',
        ]);

        $this->assertEquals('SR123456', $service->getSiteCd());
    }

    public function test_get_js_url_returns_test_url_in_test_mode(): void
    {
        $service = $this->makeService();

        $this->assertStringContainsString('testpay.kcp.co.kr', $service->getJsUrl());
    }

    public function test_get_js_url_returns_live_url_in_live_mode(): void
    {
        $service = $this->makeService([
            'is_test_mode' => false,
            'live_site_cd' => 'SR123456',
            'live_site_key' => 'live_key',
        ]);

        $jsUrl = $service->getJsUrl();
        $this->assertStringContainsString('pay.kcp.co.kr', $jsUrl);
        $this->assertStringNotContainsString('testpay', $jsUrl);
    }

    public function test_get_transaction_calls_correct_url_with_auth_headers(): void
    {
        $service = $this->makeService();

        $tno = 'KCP_TNO_TEST_001';
        $ordrIdxx = 'ORD-TEST-12345';

        Http::fake([
            'stgapi.kcp.co.kr/*' => Http::response([
                'res_cd' => '0000',
                'res_msg' => '정상처리',
                'tno' => $tno,
                'ordr_idxx' => $ordrIdxx,
                'app_no' => 'APP12345',
            ], 200),
        ]);

        $result = $service->getTransaction($tno, $ordrIdxx);

        $this->assertEquals('0000', $result['res_cd']);
        $this->assertEquals($tno, $result['tno']);

        Http::assertSent(function ($request) use ($tno, $ordrIdxx) {
            return str_contains($request->url(), 'stgapi.kcp.co.kr')
                && str_contains($request->url(), urlencode($tno))
                && $request->hasHeader('Authorization')
                && $request->hasHeader('X-Kcp-Site-Code')
                && $request->hasHeader('X-Kcp-Timestamp')
                && $request->hasHeader('X-Kcp-Signature')
                && $request['ordr_idxx'] === $ordrIdxx;
        });
    }

    public function test_get_transaction_uses_basic_auth_with_site_credentials(): void
    {
        $service = $this->makeService();

        Http::fake([
            'stgapi.kcp.co.kr/*' => Http::response(['res_cd' => '0000'], 200),
        ]);

        $service->getTransaction('TNO_001', 'ORD-001');

        $expectedAuthValue = 'Basic ' . base64_encode(self::TEST_SITE_CD . ':' . self::TEST_SITE_KEY);

        Http::assertSent(function ($request) use ($expectedAuthValue) {
            return $request->header('Authorization')[0] === $expectedAuthValue;
        });
    }

    public function test_get_transaction_throws_on_http_error(): void
    {
        $service = $this->makeService();

        Http::fake([
            'stgapi.kcp.co.kr/*' => Http::response(null, 500),
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/HTTP 500/');

        $service->getTransaction('TNO_ERR', 'ORD-ERR');
    }

    public function test_cancel_payment_calls_delete_with_correct_params(): void
    {
        $service = $this->makeService();

        $tno = 'KCP_TNO_CANCEL_001';
        $ordrIdxx = 'ORD-TEST-CANCEL';
        $cancelAmt = 50000;
        $cancelMsg = '고객 요청';

        Http::fake([
            'stgapi.kcp.co.kr/*' => Http::response([
                'res_cd' => '0000',
                'res_msg' => '취소완료',
                'tno' => $tno,
            ], 200),
        ]);

        $result = $service->cancelPayment($tno, $ordrIdxx, $cancelAmt, $cancelMsg, false);

        $this->assertEquals('0000', $result['res_cd']);

        Http::assertSent(function ($request) use ($tno, $ordrIdxx, $cancelAmt, $cancelMsg) {
            return str_contains($request->url(), 'stgapi.kcp.co.kr')
                && str_contains($request->url(), urlencode($tno))
                && $request->method() === 'DELETE'
                && $request['tno'] === $tno
                && $request['ordr_idxx'] === $ordrIdxx
                && $request['mod_type'] === 'STAX'
                && $request['mod_desc'] === $cancelMsg
                && $request['cancel_amt'] === $cancelAmt;
        });
    }

    public function test_cancel_payment_sends_part_mod_type_for_partial_cancel(): void
    {
        $service = $this->makeService();

        Http::fake([
            'stgapi.kcp.co.kr/*' => Http::response(['res_cd' => '0000'], 200),
        ]);

        $service->cancelPayment('TNO_PART', 'ORD-PART', 10000, '부분취소', true);

        Http::assertSent(function ($request) {
            return $request['mod_type'] === 'PART';
        });
    }

    public function test_cancel_payment_throws_on_non_0000_res_cd(): void
    {
        $service = $this->makeService();

        Http::fake([
            'stgapi.kcp.co.kr/*' => Http::response([
                'res_cd' => '9999',
                'res_msg' => '취소 실패',
            ], 200),
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('취소 실패');

        $service->cancelPayment('TNO_FAIL', 'ORD-FAIL', 50000, '고객 요청');
    }

    public function test_cancel_payment_throws_on_http_error(): void
    {
        $service = $this->makeService();

        Http::fake([
            'stgapi.kcp.co.kr/*' => Http::response(null, 500),
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/HTTP 500/');

        $service->cancelPayment('TNO_HTTP_ERR', 'ORD-HTTP-ERR', 50000, '오류');
    }

    public function test_get_transaction_uses_live_api_url_in_live_mode(): void
    {
        $service = $this->makeService([
            'is_test_mode' => false,
            'live_site_cd' => 'SR123456',
            'live_site_key' => 'live_key_value',
        ]);

        Http::fake([
            'api.kcp.co.kr/*' => Http::response(['res_cd' => '0000'], 200),
        ]);

        $service->getTransaction('TNO_LIVE', 'ORD-LIVE');

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'api.kcp.co.kr')
                && ! str_contains($request->url(), 'stgapi');
        });
    }
}
