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

        // 실행 권한 자가 복구: plugin:update 후 _bundled 의 0664 권한이 활성 디렉토리에 그대로
        // 회귀해 9502 ("연동 모듈 호출 오류") 가 발생하던 회귀를 결제 hot path 에서 차단.
        // Windows 에서는 chmod 자체는 no-op 에 가깝지만, is_file 가드는 의미 있음.
        $this->ensureCliExecutable($binPath);
        $planData = 'payx_data=' . ($modxData !== '' ? 'mod_data=' . $modxData : '');

        // Shell injection 방어: CLI args 의 각 값을 사전 검증 후 안전한 값만 사용.
        // 위험 문자(`"`, 제어문자, 개행) 가 포함되면 KCP CLI 인터페이스가 깨지고 cmd.exe
        // 인수 파싱이 조작될 수 있어 NhnKcpApiException 으로 즉시 거부.
        $this->assertSafeCliValue($siteCd, 'site_cd');
        $this->assertSafeCliValue($this->siteKey, 'site_key');
        $this->assertSafeCliValue($txCd, 'tx_cd');
        $this->assertSafeCliValue($paUrl, 'pa_url');
        $this->assertSafeCliValue($ordrIdxx, 'ordr_idxx');
        $this->assertSafeCliValue($encData, 'enc_data');
        $this->assertSafeCliValue($encInfo, 'enc_info');
        $this->assertSafeCliValue($custIp, 'cust_ip');
        $this->assertSafeCliValue($keyPath, 'key_path');
        $this->assertSafeCliValue($planData, 'plan_data');

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

        // escapeshellarg 로 binPath / args 각각을 안전하게 quoting (Windows 는 `"` 제거 + 큰따옴표 래핑).
        $command = escapeshellarg($binPath) . ' ' . escapeshellarg($args);

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

        // 실행 권한 자가 복구: plugin:update 후 _bundled 의 0664 권한이 활성 디렉토리에 그대로
        // 회귀해 exec() 가 빈 결과를 반환 → 9502 ("연동 모듈 호출 오류") 가 발생하던 회귀를
        // 결제 hot path 에서 차단. HealthCheckController 의 admin UI 진입 시점 복구와는 별개로
        // 사용자 결제 시점 안전망 역할.
        $this->ensureCliExecutable($binExe);

        $modxArg = $modxData !== '' ? 'mod_data=' . $modxData : '';

        // CLI args 사전 검증 — Linux 도 별도 sanitization 적용해 OS 간 동일한 가드.
        $this->assertSafeCliValue($siteCd, 'site_cd');
        $this->assertSafeCliValue($this->siteKey, 'site_key');
        $this->assertSafeCliValue($txCd, 'tx_cd');
        $this->assertSafeCliValue($paUrl, 'pa_url');
        $this->assertSafeCliValue($ordrIdxx, 'ordr_idxx');
        $this->assertSafeCliValue($encData, 'enc_data');
        $this->assertSafeCliValue($encInfo, 'enc_info');
        $this->assertSafeCliValue($custIp, 'cust_ip');
        $this->assertSafeCliValue($modxArg, 'modx_data');

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

    /**
     * KCP CLI 바이너리의 실행 권한 자가 복구.
     *
     * plugin:update 가 _bundled 의 0664 권한을 활성 디렉토리로 그대로 복사해
     * 실행 권한이 사라지면 exec() 가 빈 결과를 반환 → executeCli() 가 res_cd=9502
     * fallback 으로 떨어진다. 결제 진입 직전 호출하여 권한이 부족하면 chmod 0755
     * 로 복구, stat 캐시 무효화 후 재검증한다.
     *
     * 복구 실패 시 (PHP-FPM 이 파일 소유자가 아닌 sudo 환경 등) NhnKcpApiException
     * 으로 fail-fast — exec() 가 9502 로 끝나기 전에 운영자가 원인 (sudo chmod 필요)
     * 을 명확히 알 수 있다.
     *
     * @param  string  $binPath  KCP CLI 바이너리 절대 경로
     * @return void
     *
     * @throws NhnKcpApiException 바이너리 누락 또는 실행 권한 복구 실패 시
     */
    private function ensureCliExecutable(string $binPath): void
    {
        if (! is_file($binPath)) {
            throw new NhnKcpApiException("KCP CLI 바이너리 누락: {$binPath}");
        }

        clearstatcache(true, $binPath);
        if (is_executable($binPath)) {
            return;
        }

        $beforeMode = substr(sprintf('%o', fileperms($binPath)), -4);
        $chmodOk = @chmod($binPath, 0755);
        clearstatcache(true, $binPath);

        if ($chmodOk && is_executable($binPath)) {
            Log::info('KCP: CLI 바이너리 실행 권한 자가 복구', [
                'path' => $binPath,
                'before' => $beforeMode,
                'after' => '0755',
            ]);

            return;
        }

        Log::error('KCP: CLI 실행 권한 자가 복구 실패 — sudo chmod 필요', [
            'path' => $binPath,
            'mode' => $beforeMode,
            'owner' => fileowner($binPath),
            'php_uid' => function_exists('posix_geteuid') ? posix_geteuid() : null,
        ]);

        throw new NhnKcpApiException(
            "KCP CLI 바이너리 실행 권한 부족 — 운영자 조치 필요 (sudo chmod 755 {$binPath})"
        );
    }

    /**
     * CLI args 의 단일 값에 shell injection 위험 문자가 없는지 검증.
     *
     * 거부 대상:
     *  - 큰따옴표 (`"`) — Windows cmd.exe 의 args quoting 깨짐
     *  - 제어문자 (개행 / 캐리지리턴 / NUL / TAB) — argv 분리/명령 종료 위험
     *  - 백틱 (`` ` ``) — 일부 셸에서 명령 치환
     *
     * KCP CLI 의 정상 입력 (alphanumeric, base64, IP, URL, Windows path 등) 은
     * 모두 통과하며, 비정상 페이로드만 차단한다.
     *
     * @param  string  $value  검증할 값
     * @param  string  $key  필드 이름 (예외 메시지용)
     *
     * @throws KgInicisApiException 위험 문자 발견 시
     */
    private function assertSafeCliValue(string $value, string $key): void
    {
        // 큰따옴표 / 백틱 — 명시적 위험
        if (preg_match('/["`]/', $value) === 1) {
            throw new NhnKcpApiException(
                "KCP CLI rejected unsafe value for {$key} (contains quote/backtick)."
            );
        }

        // 제어문자 (NUL / LF / CR / TAB 등 0x00-0x1F + 0x7F)
        if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new NhnKcpApiException(
                "KCP CLI rejected unsafe value for {$key} (contains control character)."
            );
        }
    }

    private function buildLiveSiteCd(string $suffix): string
    {
        return str_starts_with($suffix, self::LIVE_SITE_CD_PREFIX) ? $suffix : self::LIVE_SITE_CD_PREFIX . $suffix;
    }
}
