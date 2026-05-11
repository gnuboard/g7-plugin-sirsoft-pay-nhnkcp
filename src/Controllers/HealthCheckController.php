<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNhnkcp\Controllers;

use Illuminate\Http\JsonResponse;

/**
 * NHN KCP 플러그인 시스템 점검 컨트롤러
 *
 * 관리자 설정 페이지에서 호출되어:
 *   - PC 결제 (Standard Pay CLI 바이너리) 실행 환경
 *   - 모바일 결제 (SmartPhone Pay SOAP 호출) 실행 환경
 * 두 가지 사전조건을 진단하고, 자동 복구 가능한 항목(예: chmod +x)은
 * 즉시 수정한 뒤 결과를 반환한다.
 *
 * 자동 복구 불가능한 항목(php-soap 미설치, exec() disabled 등)은
 * "사용자 조치 안내" 메시지(remediation)를 함께 반환한다.
 */
class HealthCheckController
{
    private const STATUS_OK = 'ok';        // 정상

    private const STATUS_FIXED = 'fixed';  // 자동 복구됨

    private const STATUS_WARNING = 'warning';  // 동작 가능하나 권장 상태 아님

    private const STATUS_ERROR = 'error';  // 사용자 조치 필요

    private string $binDir;

    public function __construct()
    {
        $this->binDir = dirname(__DIR__, 2) . '/bin';
    }

    /**
     * GET /api/plugins/sirsoft-pay_nhnkcp/admin/health
     *
     * @return JsonResponse{success: true, data: {summary: array, checks: array}}
     */
    public function check(): JsonResponse
    {
        $checks = [
            $this->checkExecFunction(),
            $this->checkBinDirectory(),
            $this->checkCliBinary('pp_cli_x64', '64-bit Linux'),
            $this->checkCliBinary('pp_cli', '32-bit Linux'),
            $this->checkCliBinary('pp_cli_exe.exe', 'Windows', requireExec: false),
            $this->checkPubKey(),
            $this->checkSoapExtension(),
            $this->checkWsdlFile(),
        ];

        $summary = $this->summarize($checks);

        return response()->json([
            'success' => true,
            'data' => [
                'summary' => $summary,
                'checks' => $checks,
            ],
        ]);
    }

    /**
     * 상태 통계: 카테고리별로 PC/모바일 결제 가능 여부 판단
     *
     * @param  array<int, array<string, mixed>>  $checks
     */
    private function summarize(array $checks): array
    {
        $byId = [];
        foreach ($checks as $c) {
            $byId[$c['id']] = $c;
        }

        $execOk = ! $this->isErroneous($byId['exec_function'] ?? null);
        $cliOk = ! $this->isErroneous($byId['cli_pp_cli_x64'] ?? null)
            || ! $this->isErroneous($byId['cli_pp_cli'] ?? null);
        $pubKeyOk = ! $this->isErroneous($byId['pub_key'] ?? null);

        $pcReady = $execOk && $cliOk && $pubKeyOk;

        $soapOk = ! $this->isErroneous($byId['soap_extension'] ?? null);
        $wsdlOk = ! $this->isErroneous($byId['wsdl_file'] ?? null);
        $mobileReady = $soapOk && $wsdlOk;

        $errorCount = 0;
        $warningCount = 0;
        $fixedCount = 0;
        foreach ($checks as $c) {
            match ($c['status']) {
                self::STATUS_ERROR => $errorCount++,
                self::STATUS_WARNING => $warningCount++,
                self::STATUS_FIXED => $fixedCount++,
                default => null,
            };
        }

        return [
            'pc_payment_ready' => $pcReady,
            'mobile_payment_ready' => $mobileReady,
            'error_count' => $errorCount,
            'warning_count' => $warningCount,
            'fixed_count' => $fixedCount,
        ];
    }

    private function isErroneous(?array $check): bool
    {
        return ($check['status'] ?? self::STATUS_ERROR) === self::STATUS_ERROR;
    }

    /**
     * PHP `exec()` 함수 활성화 여부 — disable_functions 에서 차단되면 CLI 호출 불가
     */
    private function checkExecFunction(): array
    {
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        $isDisabled = in_array('exec', $disabled, true) || ! function_exists('exec');

        if ($isDisabled) {
            return [
                'id' => 'exec_function',
                'category' => 'pc',
                'label' => 'PHP exec() 함수',
                'status' => self::STATUS_ERROR,
                'detail' => 'php.ini 의 disable_functions 에서 exec 가 차단되어 있습니다.',
                'remediation' => "php.ini 에서 disable_functions 목록에서 'exec' 를 제거한 뒤 PHP-FPM(또는 Apache) 을 재시작하세요.\n예: sudo sed -i 's/disable_functions = .*exec.*/disable_functions =/' /etc/php/8.x/fpm/php.ini && sudo systemctl restart php8.x-fpm",
            ];
        }

        return [
            'id' => 'exec_function',
            'category' => 'pc',
            'label' => 'PHP exec() 함수',
            'status' => self::STATUS_OK,
            'detail' => 'CLI 호출 가능',
        ];
    }

    /**
     * bin 디렉토리 존재 + 쓰기 가능 여부 (chmod 자동 복구 사전조건)
     */
    private function checkBinDirectory(): array
    {
        if (! is_dir($this->binDir)) {
            return [
                'id' => 'bin_directory',
                'category' => 'pc',
                'label' => 'CLI 바이너리 디렉토리',
                'status' => self::STATUS_ERROR,
                'detail' => "bin 디렉토리가 없습니다: {$this->binDir}",
                'remediation' => '플러그인을 재설치하거나, KCP 에서 제공한 CLI 바이너리 (pp_cli, pp_cli_x64, pub.key) 를 bin/ 에 복사하세요.',
            ];
        }

        $writable = is_writable($this->binDir);

        return [
            'id' => 'bin_directory',
            'category' => 'pc',
            'label' => 'CLI 바이너리 디렉토리',
            'status' => $writable ? self::STATUS_OK : self::STATUS_WARNING,
            'detail' => $writable
                ? '자동 chmod 가능'
                : '쓰기 권한 없음 — 실행 권한 자동 복구 불가',
            'remediation' => $writable
                ? null
                : "웹 프로세스가 bin/ 디렉토리에 쓸 수 있어야 자동 chmod 복구가 동작합니다.\n\n"
                . "▶ 간단 (개발/테스트):\n"
                . "  sudo chmod -R 0777 {$this->binDir}\n\n"
                . "▶ 권장 (운영):\n"
                . "  sudo chown -R thisgun:www-data {$this->binDir} && sudo chmod -R 0775 {$this->binDir}\n\n"
                . "둘 다 동일하게 동작합니다. 운영 환경에서는 후자 권장.",
        ];
    }

    /**
     * CLI 바이너리: 존재 + (Linux는) 실행 권한 — 권한 없으면 자동 chmod +x 시도
     */
    private function checkCliBinary(string $filename, string $arch, bool $requireExec = true): array
    {
        $path = $this->binDir . '/' . $filename;
        $id = 'cli_' . preg_replace('/[^a-z0-9_]/i', '_', strtolower(pathinfo($filename, PATHINFO_FILENAME)));
        $label = "CLI 바이너리 ({$arch}) — {$filename}";

        if (! file_exists($path)) {
            return [
                'id' => $id,
                'category' => 'pc',
                'label' => $label,
                'status' => self::STATUS_WARNING,
                'detail' => '파일 없음 (이 아키텍처 미사용 시 무시 가능)',
                'remediation' => null,
            ];
        }

        if (! $requireExec) {
            return [
                'id' => $id,
                'category' => 'pc',
                'label' => $label,
                'status' => self::STATUS_OK,
                'detail' => 'Windows 바이너리 — 실행 권한 검사 생략',
            ];
        }

        if (is_executable($path)) {
            return [
                'id' => $id,
                'category' => 'pc',
                'label' => $label,
                'status' => self::STATUS_OK,
                'detail' => '실행 권한 OK (' . substr(sprintf('%o', fileperms($path)), -4) . ')',
            ];
        }

        // 자동 복구: chmod +x 시도
        $beforeMode = substr(sprintf('%o', fileperms($path)), -4);
        $chmodOk = @chmod($path, 0755);

        if ($chmodOk && is_executable($path)) {
            return [
                'id' => $id,
                'category' => 'pc',
                'label' => $label,
                'status' => self::STATUS_FIXED,
                'detail' => "실행 권한 자동 복구됨: {$beforeMode} → 0755",
            ];
        }

        return [
            'id' => $id,
            'category' => 'pc',
            'label' => $label,
            'status' => self::STATUS_ERROR,
            'detail' => "실행 권한 없음 ({$beforeMode}) — 자동 복구 실패",
            'remediation' => "터미널에서 실행 권한을 부여하세요. 아래 중 하나만 실행하면 됩니다:\n\n"
                . "▶ 간단:\n  sudo chmod +x {$path}\n\n"
                . "▶ 전체 디렉토리 일괄 (자동 복구도 가능해짐):\n  sudo chmod -R 0777 {$this->binDir}",
        ];
    }

    /**
     * pub.key 파일 존재 — KCP CLI 가 결제 응답 복호화에 사용
     */
    private function checkPubKey(): array
    {
        $path = $this->binDir . '/pub.key';

        if (! file_exists($path)) {
            return [
                'id' => 'pub_key',
                'category' => 'pc',
                'label' => 'KCP 공개키 (pub.key)',
                'status' => self::STATUS_ERROR,
                'detail' => "pub.key 파일이 없습니다: {$path}",
                'remediation' => 'KCP 에서 제공받은 pub.key 파일을 bin/ 에 복사하세요. (테스트 환경은 플러그인 기본 제공)',
            ];
        }

        return [
            'id' => 'pub_key',
            'category' => 'pc',
            'label' => 'KCP 공개키 (pub.key)',
            'status' => self::STATUS_OK,
            'detail' => '파일 존재',
        ];
    }

    /**
     * PHP SOAP 확장 — 모바일 SmartPhone Pay 의 approval_key 획득에 필수
     */
    private function checkSoapExtension(): array
    {
        if (extension_loaded('soap') && class_exists(\SoapClient::class)) {
            return [
                'id' => 'soap_extension',
                'category' => 'mobile',
                'label' => 'PHP SOAP 확장',
                'status' => self::STATUS_OK,
                'detail' => 'SoapClient 사용 가능',
            ];
        }

        return [
            'id' => 'soap_extension',
            'category' => 'mobile',
            'label' => 'PHP SOAP 확장',
            'status' => self::STATUS_ERROR,
            'detail' => '모바일 결제 (SmartPhone Pay) 가 동작하지 않습니다.',
            'remediation' => "PHP SOAP 확장을 설치하고 PHP-FPM 을 재시작하세요:\n"
                . "Ubuntu/Debian: sudo apt install php8.x-soap && sudo systemctl restart php8.x-fpm\n"
                . "RHEL/CentOS:   sudo yum install php-soap && sudo systemctl restart php-fpm\n"
                . "확인: php -m | grep soap",
        ];
    }

    /**
     * WSDL 파일 — bin/ 에 KCPPaymentService.wsdl, real_KCPPaymentService.wsdl 필요
     */
    private function checkWsdlFile(): array
    {
        $wsdlFiles = ['KCPPaymentService.wsdl', 'real_KCPPaymentService.wsdl'];
        $missing = [];
        foreach ($wsdlFiles as $f) {
            if (! file_exists($this->binDir . '/' . $f)) {
                $missing[] = $f;
            }
        }

        if (! empty($missing)) {
            return [
                'id' => 'wsdl_file',
                'category' => 'mobile',
                'label' => 'WSDL 파일',
                'status' => self::STATUS_ERROR,
                'detail' => '누락: ' . implode(', ', $missing),
                'remediation' => 'KCP 에서 제공받은 WSDL 파일을 bin/ 에 복사하세요. (테스트/라이브 별로 두 개)',
            ];
        }

        return [
            'id' => 'wsdl_file',
            'category' => 'mobile',
            'label' => 'WSDL 파일',
            'status' => self::STATUS_OK,
            'detail' => '테스트/라이브 WSDL 모두 존재',
        ];
    }
}
