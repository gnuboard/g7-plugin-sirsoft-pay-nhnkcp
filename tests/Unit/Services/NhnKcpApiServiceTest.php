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

    /**
     * 결제 직전 CLI 바이너리 실행 권한 자가 복구 (9502 회귀 차단).
     *
     * 시나리오: plugin:update 후 활성 디렉토리 pp_cli/pp_cli_x64 가 _bundled 권한 (0664) 으로
     * 회귀해 실행 권한이 사라진 상태에서 결제 시도. ensureCliExecutable() 가 결제 직전
     * 자동으로 chmod 0755 + stat 캐시 무효화를 수행하여 9502 발생을 차단해야 한다.
     *
     * 단위 테스트는 ensureCliExecutable() 만 reflection 으로 직접 호출하고
     * (실제 KCP CLI 실행은 외부 의존이라 단위 범위 밖) 권한 변경 + 멱등성 + 실패 분기
     * 3가지 동작을 검증한다.
     */
    public function test_ensure_cli_executable_promotes_0664_to_0755(): void
    {
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $this->markTestSkipped('파일 모드 비트가 Windows 와 호환되지 않음.');
        }

        $tmpFile = tempnam(sys_get_temp_dir(), 'kcp_cli_test_');
        $this->assertNotFalse($tmpFile);
        file_put_contents($tmpFile, '#!/bin/sh' . PHP_EOL . 'exit 0' . PHP_EOL);
        chmod($tmpFile, 0664);
        $this->assertFalse(is_executable($tmpFile), 'precondition: 0664 는 실행 권한 없음');

        try {
            $service = $this->makeService();
            $method = (new \ReflectionClass($service))->getMethod('ensureCliExecutable');
            $method->setAccessible(true);
            $method->invoke($service, $tmpFile);

            clearstatcache(true, $tmpFile);
            $this->assertTrue(is_executable($tmpFile), 'chmod 0755 로 자가 복구되어야 함');
            $mode = substr(sprintf('%o', fileperms($tmpFile)), -4);
            $this->assertSame('0755', $mode, "권한이 0755 로 정확히 설정되어야 함 (현재: {$mode})");
        } finally {
            @unlink($tmpFile);
        }
    }

    public function test_ensure_cli_executable_is_idempotent_when_already_executable(): void
    {
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $this->markTestSkipped('파일 모드 비트가 Windows 와 호환되지 않음.');
        }

        $tmpFile = tempnam(sys_get_temp_dir(), 'kcp_cli_test_');
        $this->assertNotFalse($tmpFile);
        file_put_contents($tmpFile, '#!/bin/sh' . PHP_EOL . 'exit 0' . PHP_EOL);
        chmod($tmpFile, 0755);

        try {
            $service = $this->makeService();
            $method = (new \ReflectionClass($service))->getMethod('ensureCliExecutable');
            $method->setAccessible(true);
            // 호출이 예외 없이 통과해야 하며, 권한도 그대로여야 함.
            $method->invoke($service, $tmpFile);

            clearstatcache(true, $tmpFile);
            $mode = substr(sprintf('%o', fileperms($tmpFile)), -4);
            $this->assertSame('0755', $mode, '이미 실행 가능한 파일의 권한은 변경되지 않아야 함');
        } finally {
            @unlink($tmpFile);
        }
    }

    public function test_ensure_cli_executable_throws_when_binary_missing(): void
    {
        $service = $this->makeService();
        $method = (new \ReflectionClass($service))->getMethod('ensureCliExecutable');
        $method->setAccessible(true);

        $this->expectException(\Plugins\Sirsoft\PayNhnkcp\Exceptions\NhnKcpApiException::class);
        $this->expectExceptionMessageMatches('/CLI 바이너리/');

        $method->invoke($service, '/tmp/nonexistent_kcp_cli_' . uniqid());
    }
}
