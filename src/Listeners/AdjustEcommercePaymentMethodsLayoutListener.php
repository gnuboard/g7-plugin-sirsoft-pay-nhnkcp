<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNhnkcp\Listeners;

use App\Contracts\Extension\HookListenerInterface;

/**
 * 이커머스 결제수단 설정 화면에서 NHN KCP 간편결제를 PG 선택 불필요 항목으로 표시한다.
 */
class AdjustEcommercePaymentMethodsLayoutListener implements HookListenerInterface
{
    private const TARGET_LAYOUT = 'admin_ecommerce_settings';

    private const CORE_NO_PG_METHODS = ['point', 'deposit', 'free', 'dbank'];

    private const NHNKCP_NO_PG_METHODS = [
        'nhnkcp_payco',
        'nhnkcp_naverpay',
        'nhnkcp_naverpay_point',
        'nhnkcp_kakaopay',
        'nhnkcp_applepay',
    ];

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.layout_extension.after_apply' => [
                'method' => 'markEasyPayMethodsAsPgNotRequired',
                'type' => 'filter',
                'priority' => 30,
            ],
        ];
    }

    /**
     * @param mixed ...$args
     */
    public function handle(...$args): void {}

    /**
     * @param array<string, mixed> $layout
     * @return array<string, mixed>
     */
    public function markEasyPayMethodsAsPgNotRequired(array $layout, int $templateId): array
    {
        if (($layout['layout_name'] ?? '') !== self::TARGET_LAYOUT) {
            return $layout;
        }

        return $this->replaceNoPgMethodExpressions($layout);
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private function replaceNoPgMethodExpressions(array $node): array
    {
        foreach ($node as $key => $value) {
            if (is_array($value)) {
                $node[$key] = $this->replaceNoPgMethodExpressions($value);
                continue;
            }

            if (is_string($value)) {
                $node[$key] = $this->addNhnKcpMethodsToNoPgArray($value);
            }
        }

        return $node;
    }

    private function addNhnKcpMethodsToNoPgArray(string $expression): string
    {
        return preg_replace_callback(
            '/\[((?:\'[^\']+\'\s*,?\s*)+)\]\.includes\(\$method\.id\)/',
            function (array $matches): string {
                preg_match_all("/'([^']+)'/", $matches[1], $tokenMatches);
                $ids = $tokenMatches[1] ?? [];

                foreach (self::CORE_NO_PG_METHODS as $coreId) {
                    if (! in_array($coreId, $ids, true)) {
                        return $matches[0];
                    }
                }

                foreach (self::NHNKCP_NO_PG_METHODS as $methodId) {
                    if (! in_array($methodId, $ids, true)) {
                        $ids[] = $methodId;
                    }
                }

                return "['" . implode("','", $ids) . "'].includes(\$method.id)";
            },
            $expression
        ) ?? $expression;
    }
}
