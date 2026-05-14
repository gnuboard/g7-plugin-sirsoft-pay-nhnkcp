<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNhnkcp\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * KCP webhook 발신 IP 화이트리스트 가드 (그누보드5 settle_kcp_common.php 참고)
 *
 * 적용 라우트: vbank-notify / escrow-common-notify
 * (결제창 콜백 authCallback 은 브라우저 POST 라 IP 검증 안 함)
 *
 * 운영/테스트 모드 무관 항상 IP 검증 — KCP testadmin 의 모의입금 webhook 도 동일한
 * KCP 발신 IP 화이트리스트에 포함되므로 테스트 모드 우회 불필요.
 * 화이트리스트 외 요청은 403 차단.
 */
class RestrictKcpIp
{
    /**
     * KCP 공식 발신 IP 목록 — 그누보드5 settle_kcp_common.php 의 화이트리스트와 동일
     */
    private const KCP_NOTIFY_IPS = [
        '203.238.36.58',
        '203.238.36.160',
        '203.238.36.161',
        '203.238.36.173',
        '203.238.36.178',
        // 판교 IDC IP (2019-04-03 추가)
        '103.215.144.173',
        '103.215.144.174',
        '103.215.145.30',
    ];

    /**
     * 요청 IP 검증 — KCP 공식 발신 IP 외 모든 요청 403 차단
     *
     * @param  Request  $request  유입 요청
     * @param  Closure  $next  다음 미들웨어
     * @return Response 통과 시 다음 미들웨어 응답, 차단 시 403
     */
    public function handle(Request $request, Closure $next): Response
    {
        $clientIp = $request->ip() ?? '';

        if (! in_array($clientIp, self::KCP_NOTIFY_IPS, true)) {
            Log::warning('KCP: webhook from unauthorized IP — blocked', [
                'ip' => $clientIp,
                'url' => $request->fullUrl(),
                'user_agent' => $request->userAgent(),
            ]);

            abort(403, 'Forbidden');
        }

        return $next($request);
    }
}
