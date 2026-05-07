<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNhnkcp\Listeners;

use App\Contracts\Extension\HookListenerInterface;

class RegisterPgProviderListener implements HookListenerInterface
{
    private const PLUGIN_IDENTIFIER = 'sirsoft-pay_nhnkcp';

    private const LIVE_SITE_CD_PREFIX = 'SR';

    /**
     * 구독할 훅 매핑 반환
     *
     * @return array<string, array<string, mixed>> 훅 구독 설정
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'sirsoft-ecommerce.payment.registered_pg_providers' => [
                'method' => 'registerProvider',
                'type' => 'filter',
                'priority' => 10,
            ],
            'sirsoft-ecommerce.payment.get_client_config' => [
                'method' => 'getClientConfig',
                'type' => 'filter',
                'priority' => 10,
            ],
        ];
    }

    /**
     * 기본 핸들러 (미사용 — 개별 메서드에서 처리)
     *
     * @param  mixed  ...$args  훅 인수
     */
    public function handle(...$args): void {}

    /**
     * PG 제공자 목록에 NHN KCP 등록
     *
     * @param  array  $providers  기존 PG 제공자 목록
     * @return array NHN KCP 가 추가된 PG 제공자 목록
     */
    public function registerProvider(array $providers): array
    {
        $providers[] = [
            'id' => 'nhnkcp',
            'name_key' => 'sirsoft-pay_nhnkcp::provider.name',
            'name' => localized_label(nameKey: 'sirsoft-pay_nhnkcp::provider.name'),
            'icon' => 'credit-card',
            'supported_methods' => ['card', 'bank_transfer', 'virtual_account', 'mobile'],
        ];

        return $providers;
    }

    /**
     * PG 클라이언트 설정 제공 (프론트엔드 SDK용)
     *
     * site_cd, SDK URL, 콜백 URL 을 반환. 테스트 모드는 testpay.kcp.co.kr
     * 라이브 모드는 pay.kcp.co.kr 사용.
     *
     * @param  array  $config  기존 설정
     * @param  string  $provider  PG 제공자 ID
     * @return array 클라이언트 설정 또는 입력 그대로 (다른 PG 인 경우)
     */
    public function getClientConfig(array $config, string $provider): array
    {
        if ($provider !== 'nhnkcp') {
            return $config;
        }

        $settings = $this->getPluginSettings();
        $isTest = $settings['is_test_mode'] ?? true;

        $liveSuffix = $settings['live_site_cd'] ?? '';
        $liveSiteCd = str_starts_with($liveSuffix, self::LIVE_SITE_CD_PREFIX)
            ? $liveSuffix
            : self::LIVE_SITE_CD_PREFIX . $liveSuffix;

        $siteCd = $isTest
            ? ($settings['test_site_cd'] ?? 'T0000')
            : $liveSiteCd;

        $sdkUrl = $isTest
            ? 'https://testpay.kcp.co.kr/plugin/payplus_web.jsp'
            : 'https://pay.kcp.co.kr/plugin/payplus_web.jsp';

        return array_merge($config, [
            'client_id' => $siteCd,
            'sdk_url' => $sdkUrl,
            'callback_urls' => [
                'callback' => '/plugins/sirsoft-pay_nhnkcp/payment/callback',
            ],
        ]);
    }

    private function getPluginSettings(): array
    {
        return plugin_settings(self::PLUGIN_IDENTIFIER);
    }
}
