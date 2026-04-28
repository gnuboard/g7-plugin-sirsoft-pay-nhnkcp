<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\Pay\Nhnkcp\Listeners;

use App\Contracts\Extension\HookListenerInterface;

class RegisterPgProviderListener implements HookListenerInterface
{
    private const PLUGIN_IDENTIFIER = 'sirsoft-pay-nhnkcp';

    private const LIVE_SITE_CD_PREFIX = 'SR';

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

    public function handle(...$args): void {}

    public function registerProvider(array $providers): array
    {
        $providers[] = [
            'id' => 'nhnkcp',
            'name' => ['ko' => 'NHN KCP', 'en' => 'NHN KCP'],
            'icon' => 'credit-card',
            'supported_methods' => ['card', 'bank_transfer', 'virtual_account', 'mobile'],
        ];

        return $providers;
    }

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
                'callback' => '/plugins/sirsoft-pay-nhnkcp/payment/callback',
            ],
        ]);
    }

    private function getPluginSettings(): array
    {
        return plugin_settings(self::PLUGIN_IDENTIFIER);
    }
}
