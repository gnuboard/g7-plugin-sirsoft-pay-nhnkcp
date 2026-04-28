<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\Pay\Nhnkcp\Services;

use App\Services\PluginSettingsService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class NhnKcpApiService
{
    private const PLUGIN_IDENTIFIER = 'sirsoft-pay-nhnkcp';

    private const API_BASE_TEST = 'https://stgapi.kcp.co.kr/std/payer';

    private const API_BASE_LIVE = 'https://api.kcp.co.kr/std/payer';

    private const JS_URL_TEST = 'https://testpay.kcp.co.kr/plugin/payplus_web.jsp';

    private const JS_URL_LIVE = 'https://pay.kcp.co.kr/plugin/payplus_web.jsp';

    private const LIVE_SITE_CD_PREFIX = 'SR';

    private bool $isTest;

    private string $siteCd;

    private string $siteKey;

    public function __construct(PluginSettingsService $pluginSettingsService)
    {
        $settings = $pluginSettingsService->get(self::PLUGIN_IDENTIFIER) ?? [];
        $this->isTest = $settings['is_test_mode'] ?? true;
        $this->siteCd = $this->isTest
            ? ($settings['test_site_cd'] ?? 'T0000')
            : $this->buildLiveSiteCd($settings['live_site_cd'] ?? '');
        $this->siteKey = $this->isTest
            ? ($settings['test_site_key'] ?? '')
            : ($settings['live_site_key'] ?? '');
    }

    public function getSiteCd(): string
    {
        return $this->siteCd;
    }

    public function getJsUrl(): string
    {
        return $this->isTest ? self::JS_URL_TEST : self::JS_URL_LIVE;
    }

    /**
     * KCP 거래 조회 API 호출 (승인 확인)
     *
     * 브라우저 콜백으로 수신한 tno를 이용해 KCP 서버에서 거래 상세를 조회합니다.
     *
     * @param string $tno       KCP 거래번호
     * @param string $ordrIdxx  주문번호
     * @return array KCP 거래 상세 데이터
     * @throws \Exception API 호출 실패 시
     */
    public function getTransaction(string $tno, string $ordrIdxx): array
    {
        $timestamp = (string) now()->timestamp;
        $signature = $this->buildSignature($timestamp);

        $url = $this->apiBase() . '/transactions/' . urlencode($tno);

        $response = Http::withHeaders([
            'Authorization' => 'Basic ' . base64_encode($this->siteCd . ':' . $this->siteKey),
            'X-Kcp-Site-Code' => $this->siteCd,
            'X-Kcp-Timestamp' => $timestamp,
            'X-Kcp-Signature' => $signature,
        ])->get($url, [
            'ordr_idxx' => $ordrIdxx,
        ]);

        if ($response->failed()) {
            throw new \Exception('KCP transaction inquiry API error: HTTP ' . $response->status());
        }

        return $response->json() ?? [];
    }

    /**
     * KCP 결제 취소 API 호출
     *
     * @param string $tno       KCP 거래번호
     * @param string $ordrIdxx  주문번호
     * @param int    $cancelAmt 취소 금액
     * @param string $cancelMsg 취소 사유
     * @param bool   $isPartial 부분 취소 여부
     * @return array KCP 응답 데이터
     * @throws \Exception API 호출 실패 시
     */
    public function cancelPayment(
        string $tno,
        string $ordrIdxx,
        int $cancelAmt,
        string $cancelMsg,
        bool $isPartial = false,
    ): array {
        $timestamp = (string) now()->timestamp;
        $signature = $this->buildSignature($timestamp);

        $url = $this->apiBase() . '/transactions/' . urlencode($tno);

        $response = Http::withHeaders([
            'Authorization' => 'Basic ' . base64_encode($this->siteCd . ':' . $this->siteKey),
            'Content-Type' => 'application/json',
            'X-Kcp-Site-Code' => $this->siteCd,
            'X-Kcp-Timestamp' => $timestamp,
            'X-Kcp-Signature' => $signature,
        ])->delete($url, [
            'site_cd' => $this->siteCd,
            'tno' => $tno,
            'ordr_idxx' => $ordrIdxx,
            'mod_type' => $isPartial ? 'PART' : 'STAX',
            'mod_desc' => $cancelMsg,
            'cancel_amt' => $cancelAmt,
        ]);

        if ($response->failed()) {
            throw new \Exception('KCP cancel API error: HTTP ' . $response->status());
        }

        $result = $response->json() ?? [];

        $resultCode = $result['res_cd'] ?? '';

        if ($resultCode !== '0000') {
            Log::error('KCP cancel failed', [
                'res_cd' => $resultCode,
                'res_msg' => $result['res_msg'] ?? '',
                'tno' => $tno,
            ]);
            throw new \Exception($result['res_msg'] ?? 'KCP cancel failed');
        }

        return $result;
    }

    /**
     * HMAC-SHA256 서명 생성
     * site_cd + "\n" + timestamp + "\n" + site_key
     */
    private function buildSignature(string $timestamp): string
    {
        $message = $this->siteCd . "\n" . $timestamp . "\n" . $this->siteKey;

        return base64_encode(hash_hmac('sha256', $message, $this->siteKey, true));
    }

    private function apiBase(): string
    {
        return $this->isTest ? self::API_BASE_TEST : self::API_BASE_LIVE;
    }

    private function buildLiveSiteCd(string $suffix): string
    {
        return str_starts_with($suffix, self::LIVE_SITE_CD_PREFIX) ? $suffix : self::LIVE_SITE_CD_PREFIX . $suffix;
    }
}
