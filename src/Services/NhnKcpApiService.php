<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNhnkcp\Services;

use App\Extension\HookManager;
use App\Services\PluginSettingsService;
use Illuminate\Support\Facades\Log;
use Plugins\Sirsoft\PayNhnkcp\Exceptions\NhnKcpApiException;

class NhnKcpApiService
{
    private const PLUGIN_IDENTIFIER = 'sirsoft-pay_nhnkcp';

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

    private string $escrowSiteCd;

    private string $siteKey;

    private string $binDir;

    public function __construct(PluginSettingsService $pluginSettingsService)
    {
        $settings = $pluginSettingsService->get(self::PLUGIN_IDENTIFIER) ?? [];
        $this->isTest = $settings['is_test_mode'] ?? true;
        $this->siteCd = $this->isTest
            ? ($settings['test_site_cd'] ?? 'T0000')
            : $this->buildLiveSiteCd($settings['live_site_cd'] ?? '');
        $this->escrowSiteCd = $this->isTest
            ? ($settings['escrow_test_site_cd'] ?? 'T0007')
            : $this->siteCd;
        $this->siteKey = $this->isTest
            ? ($settings['test_site_key'] ?? '')
            : ($settings['live_site_key'] ?? '');
        $this->binDir = dirname(__DIR__, 2) . '/bin';
    }

    /**
     * 활성 site_cd 반환 (테스트/라이브 자동 분기)
     *
     * @return string KCP site_cd
     */
    public function getSiteCd(): string
    {
        return $this->siteCd;
    }

    /**
     * Standard Pay JS SDK URL 반환 (테스트/라이브 자동 분기)
     *
     * @return string SDK URL
     */
    public function getJsUrl(): string
    {
        return $this->isTest ? self::JS_URL_TEST : self::JS_URL_LIVE;
    }

    /**
     * KCP 결제 승인 (CLI 방식)
     *
     * Standard Pay 결제창 완료 후 받은 enc_data / enc_info 로 KCP CLI 를 통해
     * 서버 승인 요청. 응답 res_cd = '0000' 이면 성공.
     *
     * @param  string  $encData  KCP 암호화 결제 데이터
     * @param  string  $encInfo  KCP 암호화 결제 정보
     * @param  string  $ordrIdxx  주문번호
     * @param  string  $custIp  고객 IP
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

        // 훅: 결제 취소 전 (본인인증 등 확장 지점)
        HookManager::doAction('sirsoft-pay_nhnkcp.payment.before_cancel', $tno, $ordrIdxx, $cancelAmt, $cancelMsg);

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
            throw new NhnKcpApiException($result['res_msg'] ?? 'KCP cancel failed');
        }

        // 훅: 결제 취소 완료 후 (외부 소비자 후처리 확장 지점)
        HookManager::doAction('sirsoft-pay_nhnkcp.payment.after_cancel', $tno, $result);

        return $result;
    }

    /**
     * KCP 에스크로 배송 등록 (CLI 방식, mod_type=STE1)
     *
     * 에스크로 결제 후 상품을 발송할 때 KCP에 운송장번호를 등록합니다.
     * 에스크로 테스트 결제는 T0007 site_cd를 사용하므로 escrowSiteCd로 호출합니다.
     *
     * @param  string  $tno       KCP 에스크로 거래번호
     * @param  string  $ordrIdxx  주문번호
     * @param  string  $deliNumb  운송장번호
     * @param  string  $deliCorp  택배사코드 (KCP 코드: '04'=CJ대한통운, '05'=한진택배 등)
     * @return array 파싱된 KCP 응답
     */
    public function registerEscrowDelivery(
        string $tno,
        string $ordrIdxx,
        string $deliNumb,
        string $deliCorp,
    ): array {
        $modxData = 'tno=' . $tno . chr(31)
            . 'mod_type=STE1' . chr(31)
            . 'deli_numb=' . $deliNumb . chr(31)
            . 'deli_corp=' . $deliCorp . chr(31);

        $result = $this->executeCli(
            txCd: self::TX_CANCEL,
            ordrIdxx: $ordrIdxx,
            encData: '',
            encInfo: '',
            custIp: '127.0.0.1',
            modxData: $modxData,
            siteCdOverride: $this->escrowSiteCd,
        );

        if (($result['res_cd'] ?? '') !== '0000') {
            Log::error('KCP CLI escrow delivery register failed', [
                'res_cd' => $result['res_cd'] ?? '',
                'res_msg' => $result['res_msg'] ?? '',
                'tno' => $tno,
            ]);
            throw new NhnKcpApiException($result['res_msg'] ?? 'KCP escrow delivery registration failed');
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
        string $siteCdOverride = '',
    ): array {
        $paUrl = $this->isTest ? self::PA_URL_TEST : self::PA_URL_LIVE;
        $siteCd = $siteCdOverride !== '' ? $siteCdOverride : $this->siteCd;

        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $resData = $this->executeCliWindows($txCd, $ordrIdxx, $encData, $encInfo, $custIp, $paUrl, $modxData, $siteCd);
        } else {
            $resData = $this->executeCliLinux($txCd, $ordrIdxx, $encData, $encInfo, $custIp, $paUrl, $modxData, $siteCd);
        }

        Log::debug('KCP CLI response', [
            'tx_cd' => $txCd,
            'ordr_idxx' => $ordrIdxx,
            'res_data' => $resData,
        ]);

        if ($resData === '') {
            $resData = 'res_cd=9502' . chr(31) . 'res_msg=연동 모듈 호출 오류';
        }

        // KCP Windows CLI는 CP949(EUC-KR)로 한글을 출력 — MySQL UTF-8 저장을 위해 변환
        if (! mb_check_encoding($resData, 'UTF-8')) {
            $converted = mb_convert_encoding($resData, 'UTF-8', 'CP949');
            if ($converted !== false) {
                $resData = $converted;
            }
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
        string $siteCd = '',
    ): string {
        $siteCd = $siteCd !== '' ? $siteCd : $this->siteCd;
        $keyPath = str_replace('/', DIRECTORY_SEPARATOR, $this->binDir . '/pub.key');
        $binPath = str_replace('/', DIRECTORY_SEPARATOR, $this->binDir . '/pp_cli_exe.exe');
        $planData = 'payx_data=' . ($modxData !== '' ? 'mod_data=' . $modxData : '');

        $args = 'site_cd=' . $siteCd . ','
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
            . 'plan_data=' . $planData;

        $command = '"' . $binPath . '" "' . $args . '"';

        Log::debug('KCP CLI command (Windows)', ['command' => $command]);

        exec($command, $output, $returnCode);

        Log::debug('KCP CLI exec result', [
            'return_code' => $returnCode,
            'output_lines' => $output,
        ]);

        // Windows 코드페이지 변경 메시지('Active code page: ...') 등 비-KCP 라인 제거
        $kcpLines = array_filter($output, static fn (string $line) => str_contains($line, 'res_cd='));

        if (empty($kcpLines)) {
            return '';
        }

        return (string) array_values($kcpLines)[0];
    }

    private function executeCliLinux(
        string $txCd,
        string $ordrIdxx,
        string $encData,
        string $encInfo,
        string $custIp,
        string $paUrl,
        string $modxData,
        string $siteCd = '',
    ): string {
        $siteCd = $siteCd !== '' ? $siteCd : $this->siteCd;
        $binExe = PHP_INT_MAX === 2147483647
            ? $this->binDir . '/pp_cli'
            : $this->binDir . '/pp_cli_x64';

        $modxArg = $modxData !== '' ? 'mod_data=' . $modxData : '';

        $args = 'home=' . $this->binDir . ','
            . 'site_cd=' . $siteCd . ','
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
