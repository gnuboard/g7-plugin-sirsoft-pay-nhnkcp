<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\Pay\Nhnkcp\Services;

use App\Services\PluginSettingsService;
use Illuminate\Support\Facades\Log;

class NhnKcpApiService
{
    private const PLUGIN_IDENTIFIER = 'sirsoft-pay-nhnkcp';

    private const PA_URL_TEST = 'testpaygw.kcp.co.kr';

    private const PA_URL_LIVE = 'paygw.kcp.co.kr';

    private const PA_PORT = '8090';

    private const TX_APPROVE = '00100000';

    private const TX_CANCEL = '00200000';

    private const JS_URL_TEST = 'https://testpay.kcp.co.kr/plugin/payplus_web.jsp';

    private const JS_URL_LIVE = 'https://pay.kcp.co.kr/plugin/payplus_web.jsp';

    private const LIVE_SITE_CD_PREFIX = 'SR';

    private const LOG_LEVEL = '3';

    private bool $isTest;

    private string $siteCd;

    private string $siteKey;

    private string $binDir;

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
        $this->binDir = dirname(__DIR__, 2) . '/bin';
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
     * KCP 결제 승인 (CLI 방식)
     *
     * 브라우저 콜백으로 수신한 enc_data, enc_info를 CLI에 전달하여 최종 승인합니다.
     *
     * @param string $encData  KCP 암호화 결제 데이터
     * @param string $encInfo  KCP 암호화 결제 정보
     * @param string $ordrIdxx 주문번호
     * @param string $custIp   고객 IP
     * @return array 파싱된 KCP 응답 (res_cd, res_msg, tno, app_no, card_no, quota 등)
     */
    public function approvePayment(string $encData, string $encInfo, string $ordrIdxx, string $custIp): array
    {
        return $this->executeCli(
            txCd: self::TX_APPROVE,
            ordrIdxx: $ordrIdxx,
            encData: $encData,
            encInfo: $encInfo,
            custIp: $custIp,
        );
    }

    /**
     * KCP 결제 취소 (CLI 방식)
     *
     * @param string $tno       KCP 원거래 거래번호
     * @param string $ordrIdxx  주문번호
     * @param int    $cancelAmt 취소 금액
     * @param string $cancelMsg 취소 사유
     * @param bool   $isPartial 부분 취소 여부
     * @param int    $totalAmt  원거래 결제 금액 (부분취소 시 rem_mny 계산에 사용)
     * @return array 파싱된 KCP 응답
     */
    public function cancelPayment(
        string $tno,
        string $ordrIdxx,
        int $cancelAmt,
        string $cancelMsg,
        bool $isPartial = false,
        int $totalAmt = 0,
    ): array {
        $modType = $isPartial ? 'RN07' : 'STSC';

        $modxData = 'tno=' . $tno . chr(31)
            . 'mod_type=' . $modType . chr(31)
            . 'mod_desc=' . $cancelMsg . chr(31);

        if ($isPartial && $totalAmt > 0) {
            $remMny = $totalAmt - $cancelAmt;
            $modxData .= 'rem_mny=' . $remMny . chr(31)
                . 'mod_mny=' . $cancelAmt . chr(31);
        }

        $result = $this->executeCli(
            txCd: self::TX_CANCEL,
            ordrIdxx: $ordrIdxx,
            encData: '',
            encInfo: '',
            custIp: '127.0.0.1',
            modxData: $modxData,
        );

        if (($result['res_cd'] ?? '') !== '0000') {
            Log::error('KCP CLI cancel failed', [
                'res_cd' => $result['res_cd'] ?? '',
                'res_msg' => $result['res_msg'] ?? '',
                'tno' => $tno,
            ]);
            throw new \Exception($result['res_msg'] ?? 'KCP cancel failed');
        }

        return $result;
    }

    private function executeCli(
        string $txCd,
        string $ordrIdxx,
        string $encData,
        string $encInfo,
        string $custIp,
        string $modxData = '',
    ): array {
        $paUrl = $this->isTest ? self::PA_URL_TEST : self::PA_URL_LIVE;

        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $resData = $this->executeCliWindows($txCd, $ordrIdxx, $encData, $encInfo, $custIp, $paUrl, $modxData);
        } else {
            $resData = $this->executeCliLinux($txCd, $ordrIdxx, $encData, $encInfo, $custIp, $paUrl, $modxData);
        }

        if ($resData === '') {
            $resData = 'res_cd=9502' . chr(31) . 'res_msg=연동 모듈 호출 오류';
        }

        parse_str(str_replace(chr(31), '&', $resData), $result);

        return $result;
    }

    private function executeCliWindows(
        string $txCd,
        string $ordrIdxx,
        string $encData,
        string $encInfo,
        string $custIp,
        string $paUrl,
        string $modxData,
    ): string {
        $keyPath = $this->binDir . '/pub.key';
        $planData = 'payx_data=' . ($modxData !== '' ? 'mod_data=' . $modxData : '');

        $command = $this->binDir . '/pp_cli_exe.exe "'
            . 'site_cd=' . $this->siteCd . ','
            . 'site_key=' . $this->siteKey . ','
            . 'tx_cd=' . $txCd . ','
            . 'pa_url=' . $paUrl . ','
            . 'pa_port=' . self::PA_PORT . ','
            . 'ordr_idxx=' . $ordrIdxx . ','
            . 'enc_data=' . $encData . ','
            . 'enc_info=' . $encInfo . ','
            . 'trace_no=,'
            . 'cust_ip=' . $custIp . ','
            . 'key_path=' . $keyPath . ','
            . 'log_path=,'
            . 'log_level=' . self::LOG_LEVEL . ','
            . 'plan_data=' . $planData
            . '"';

        return (string) exec($command);
    }

    private function executeCliLinux(
        string $txCd,
        string $ordrIdxx,
        string $encData,
        string $encInfo,
        string $custIp,
        string $paUrl,
        string $modxData,
    ): string {
        $binExe = PHP_INT_MAX === 2147483647
            ? $this->binDir . '/pp_cli'
            : $this->binDir . '/pp_cli_x64';

        $modxArg = $modxData !== '' ? 'mod_data=' . $modxData : '';

        $args = 'home=' . $this->binDir . ','
            . 'site_cd=' . $this->siteCd . ','
            . 'site_key=' . $this->siteKey . ','
            . 'tx_cd=' . $txCd . ','
            . 'pa_url=' . $paUrl . ','
            . 'pa_port=' . self::PA_PORT . ','
            . 'ordr_idxx=' . $ordrIdxx . ','
            . 'enc_data=' . $encData . ','
            . 'enc_info=' . $encInfo . ','
            . 'trace_no=,'
            . 'cust_ip=' . $custIp . ','
            . 'modx_data=' . $modxArg . ','
            . 'log_path=,'
            . 'log_level=' . self::LOG_LEVEL . ','
            . 'opt=';

        $command = $binExe . ' ' . escapeshellarg('-h') . ' ' . escapeshellarg($args);

        return (string) exec($command);
    }

    private function buildLiveSiteCd(string $suffix): string
    {
        return str_starts_with($suffix, self::LIVE_SITE_CD_PREFIX) ? $suffix : self::LIVE_SITE_CD_PREFIX . $suffix;
    }
}
