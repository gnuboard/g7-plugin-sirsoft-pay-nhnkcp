<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNhnkcp\Tests\Feature\Middleware;

use Plugins\Sirsoft\PayNhnkcp\Tests\PluginTestCase;

/**
 * RestrictKcpIp 미들웨어 회귀 테스트
 *
 * 적용 라우트: vbank-notify, escrow-common-notify (KCP 서버 발신 webhook)
 *   - 운영/테스트 모드 무관 항상 화이트리스트 검증
 *   - 화이트리스트 외 IP 403 차단
 *
 * 그누보드5 settle_kcp_common.php 의 IP 화이트리스트와 동일.
 */
class RestrictKcpIpTest extends PluginTestCase
{
    private const KCP_WHITELIST_IP = '203.238.36.58';

    private const KCP_PANGYO_IP = '103.215.144.173';

    private const UNAUTHORIZED_IP = '1.2.3.4';

    private function postVbankNotify(string $remoteIp): \Illuminate\Testing\TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $remoteIp])
            ->post('/plugins/sirsoft-pay_nhnkcp/payment/vbank-notify', [
                'tno'       => 'KCP_TNO_TEST',
                'order_no'  => 'ORD-IP-TEST',
                'tx_cd'     => 'TX00',
                'op_cd'     => '50',
                'ipgm_mnyx' => 30000,
            ]);
    }

    public function test_blocks_unauthorized_ip(): void
    {
        $response = $this->postVbankNotify(self::UNAUTHORIZED_IP);

        $response->assertForbidden();
    }

    public function test_allows_whitelisted_kcp_ip(): void
    {
        $response = $this->postVbankNotify(self::KCP_WHITELIST_IP);

        // 미들웨어 통과 후 컨트롤러까지 도달 → 200 + KCP result HTML 응답.
        // (주문 미존재로 컨트롤러는 result=0000 응답하지만 핵심은 403 이 아니라는 것)
        $response->assertOk();
        $this->assertStringContainsString('name="result"', $response->getContent());
    }

    public function test_allows_pangyo_idc_ip(): void
    {
        $response = $this->postVbankNotify(self::KCP_PANGYO_IP);

        $response->assertOk();
    }

    /**
     * 회귀: 테스트 환경에서도 IP 검증 — 화이트리스트 외 localhost(127.0.0.1) 도 403.
     * 운영의 KCP testadmin 모의입금 webhook 도 동일한 KCP 발신 IP 에서 오므로 우회 불필요.
     */
    public function test_blocks_localhost_loopback(): void
    {
        $response = $this->postVbankNotify('127.0.0.1');

        $response->assertForbidden();
    }

    /**
     * 화이트리스트 8개 IP 전수 통과 — 그누보드5 settle_kcp_common.php 와 동일.
     */
    public function test_all_whitelisted_ips_pass(): void
    {
        $whitelist = [
            '203.238.36.58',
            '203.238.36.160',
            '203.238.36.161',
            '203.238.36.173',
            '203.238.36.178',
            '103.215.144.173',
            '103.215.144.174',
            '103.215.145.30',
        ];

        foreach ($whitelist as $ip) {
            $response = $this->postVbankNotify($ip);
            $response->assertStatus(200, "Whitelisted IP {$ip} should not be blocked");
        }
    }
}
