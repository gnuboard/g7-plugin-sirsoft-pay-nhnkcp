/**
 * 주문 생성 API 응답 인터셉터
 *
 * 체크아웃 템플릿(_checkout_summary.json)에는 'sirsoft-tosspayments' 분기만
 * 정의되어 있어서, 'sirsoft-nhnkcp' PG는 navigate 기본 분기로 떨어져
 * /shop/orders/{order_number}/complete 로 이동해버림 (결제창 미노출).
 *
 * 코어/템플릿 수정 없이 이 문제를 우회하기 위해 plugin loading 시점에
 * window.fetch 를 래핑해 다음을 수행:
 *
 *   1. POST /api/modules/sirsoft-ecommerce/user/orders 요청 body에서
 *      nhnkcp_* 간편결제 수단을 'card'로 교체 후 서버 전송
 *   2. 응답에서 data.pg_provider === 'sirsoft-nhnkcp' 이면
 *      requestPayment 핸들러를 직접 호출하여 결제창 띄움
 *   3. data.redirect_url 을 현재 URL로 교체하고 requires_pg_payment를 false로 변경
 *      → 템플릿 fallback 분기의 navigate 가 navigate-to-self 가 되어 실질적 이동 없음
 *
 * 결과: 체크아웃 페이지에 머문 채 PG 팝업이 뜨고, PG 콜백이 정식 complete 페이지로 redirect.
 */

import { requestPaymentHandler } from './handlers/requestPayment';
import {
    applePayUnsupportedMessage,
    isIosMobileDevice,
    isNhnKcpApplePayMethod,
} from './support/applePayDevice';

const ORDER_CREATE_PATH = '/api/modules/sirsoft-ecommerce/user/orders';
const TARGET_PG_PROVIDER = 'sirsoft-nhnkcp';
const PLUGIN_IDENTIFIER = 'sirsoft-pay_nhnkcp';
const EASY_PAY_PREFIX = 'nhnkcp_';

const logger = {
    info: (...args: unknown[]) => console.info(`[${PLUGIN_IDENTIFIER}]`, ...args),
    warn: (...args: unknown[]) => console.warn(`[${PLUGIN_IDENTIFIER}]`, ...args),
    error: (...args: unknown[]) => console.error(`[${PLUGIN_IDENTIFIER}]`, ...args),
};

interface OrderCreateResponseBody {
    success?: boolean;
    message?: string;
    data?: {
        order?: {
            order_number?: string;
            total_tax_amount?: number;
            total_vat_amount?: number;
            total_tax_free_amount?: number;
        };
        redirect_url?: string;
        requires_pg_payment?: boolean;
        pg_provider?: string;
        pg_payment_data?: Record<string, unknown>;
    };
}

function extractUrl(input: RequestInfo | URL): string {
    if (typeof input === 'string') return input;
    if (input instanceof URL) return input.toString();
    if (typeof Request !== 'undefined' && input instanceof Request) return input.url;
    return String(input);
}

function extractMethod(input: RequestInfo | URL, init?: RequestInit): string {
    if (init?.method) return init.method.toUpperCase();
    if (typeof Request !== 'undefined' && input instanceof Request) return input.method.toUpperCase();
    return 'GET';
}

function isTargetEndpoint(url: string, method: string): boolean {
    if (method !== 'POST') return false;
    const path = url.split('?')[0].split('#')[0];
    return path === ORDER_CREATE_PATH || path.endsWith(ORDER_CREATE_PATH);
}

function buildNoOpRedirectUrl(): string {
    return window.location.pathname + window.location.search + window.location.hash;
}

function mutateResponse(originalResponse: Response, mutatedBody: OrderCreateResponseBody): Response {
    const json = JSON.stringify(mutatedBody);
    return new Response(json, {
        status: originalResponse.status,
        statusText: originalResponse.statusText,
        headers: originalResponse.headers,
    });
}

function buildPaymentMethodBlockedResponse(message: string): Response {
    return new Response(JSON.stringify({
        success: false,
        message,
        error: message,
        errors: {
            payment_method: [message],
        },
    }), {
        status: 422,
        statusText: 'Unprocessable Content',
        headers: {
            'Content-Type': 'application/json',
        },
    });
}

function extractPaymentMethodFromBody(body: string): string | undefined {
    try {
        const parsed = JSON.parse(body) as Record<string, unknown>;
        return parsed['payment_method'] as string | undefined;
    } catch {
        return undefined;
    }
}

function replacePaymentMethodInBody(body: string, replacement: string): string {
    try {
        const parsed = JSON.parse(body) as Record<string, unknown>;
        parsed['payment_method'] = replacement;
        return JSON.stringify(parsed);
    } catch {
        return body;
    }
}

export function installOrderResponseInterceptor(): void {
    if (typeof window === 'undefined' || typeof window.fetch !== 'function') {
        return;
    }

    const flag = '__sirsoftNhnkcpInterceptorInstalled' as const;
    const w = window as unknown as Record<string, unknown>;
    if (w[flag]) {
        return;
    }
    w[flag] = true;

    // 최초 설치 플러그인이 원본 브라우저 fetch를 보존 (다른 PG 인터셉터가 쌓이기 전)
    const ORIGINAL_FETCH_KEY = '__sirsoftPgOriginalFetch';
    if (!w[ORIGINAL_FETCH_KEY]) {
        w[ORIGINAL_FETCH_KEY] = window.fetch.bind(window);
    }
    // easy pay 요청 시 다른 PG 인터셉터(KG 이니시스 등)를 완전히 우회하는 원본 fetch
    const browserFetch = w[ORIGINAL_FETCH_KEY] as typeof fetch;

    const originalFetch = window.fetch.bind(window);

    window.fetch = async function patchedFetch(
        input: RequestInfo | URL,
        init?: RequestInit
    ): Promise<Response> {
        const url = extractUrl(input);
        const method = extractMethod(input, init);

        // 주문 생성 엔드포인트가 아니면 바로 통과
        if (!isTargetEndpoint(url, method)) {
            return originalFetch(input, init);
        }

        // 요청 body에서 payment_method 추출 (nhnkcp_* 간편결제 감지)
        let originalPaymentMethod: string | undefined;
        let modifiedInit = init;

        if (init?.body && typeof init.body === 'string') {
            const pm = extractPaymentMethodFromBody(init.body);
            if (typeof pm === 'string' && pm.startsWith(EASY_PAY_PREFIX)) {
                originalPaymentMethod = pm;
                // nhnkcp_* → 'card'로 교체해 서버 전송 (서버 ValidationEnum에 없는 값 방지)
                modifiedInit = { ...init, body: replacePaymentMethodInBody(init.body, 'card') };
                logger.info(`easy pay detected: replacing payment_method '${pm}' → 'card'`);
            }
        }

        // G7Core 로컬 상태 fallback — 간편결제 버튼 클릭 시 serverPaymentMethod='card'로
        // 서버에 전달되어 요청 body에서 감지 불가인 경우 대비
        if (!originalPaymentMethod) {
            try {
                // eslint-disable-next-line @typescript-eslint/no-explicit-any
                const localPm = ((window as any).G7Core)?.state?.getLocal?.()?.paymentMethod;
                if (typeof localPm === 'string' && localPm.startsWith(EASY_PAY_PREFIX)) {
                    originalPaymentMethod = localPm;
                    logger.info(`easy pay detected from local state: '${localPm}'`);
                }
            } catch { /* ignore */ }
        }

        if (isNhnKcpApplePayMethod(originalPaymentMethod) && !isIosMobileDevice()) {
            const message = applePayUnsupportedMessage();
            logger.warn(message);
            return buildPaymentMethodBlockedResponse(message);
        }

        // easy pay일 때 browserFetch(원본)를 사용해 KG 이니시스 등 다른 PG 인터셉터 우회
        // 기본 PG가 KG 이니시스여도 NHN KCP 결제창이 열리도록 함
        const fetchFn = originalPaymentMethod ? browserFetch : originalFetch;
        const response = await fetchFn(input, modifiedInit);

        let cloned: Response;
        try {
            cloned = response.clone();
        } catch {
            return response;
        }

        let body: OrderCreateResponseBody | null = null;
        try {
            body = (await cloned.json()) as OrderCreateResponseBody;
        } catch {
            return response;
        }

        const data = body?.data;
        if (!data) return response;

        const requiresPg = data.requires_pg_payment === true;
        const isNhnkcp = data.pg_provider === TARGET_PG_PROVIDER;

        // 일반 결제: pg_provider가 nhnkcp이고 requires_pg_payment=true인 경우에만 처리
        // 간편결제: pg_provider 무관하게 NHN KCP 처리 (기본 PG가 KG 이니시스여도 KCP 결제창 띄움)
        if (!originalPaymentMethod && (!requiresPg || !isNhnkcp)) {
            return response;
        }
        if (originalPaymentMethod && !data.order && !data.pg_payment_data) {
            return response;
        }

        // pg_payment_data: 서버 응답에 포함되거나,
        // 간편결제 + 기본 PG가 타 PG인 경우 data.order에서 직접 구성
        let pgPaymentData = data.pg_payment_data;
        if (!pgPaymentData && originalPaymentMethod && data.order) {
            const orderData = data.order as Record<string, unknown>;
            const options = orderData.options as Array<Record<string, unknown>> | undefined;
            const firstName = (options?.[0]?.product_name as string | undefined) ?? String(orderData.order_number ?? '');
            const orderName = (options?.length ?? 0) > 1
                ? `${firstName} 외 ${(options?.length ?? 0) - 1}건`
                : firstName;
            pgPaymentData = {
                order_number: orderData.order_number,
                order_name: orderName,
                amount: Math.floor(Number(orderData.total_due_amount ?? orderData.total_amount ?? 0)),
                currency: 'KRW',
                customer_name: orderData.orderer_name ?? null,
                customer_email: orderData.orderer_email ?? null,
                customer_phone: String(orderData.orderer_phone ?? '').replace(/[^0-9]/g, ''),
            };
            logger.info('pg_payment_data constructed from order (기본 PG가 타 PG)', {
                order_number: pgPaymentData.order_number,
                amount: pgPaymentData.amount,
            });
        }

        if (!pgPaymentData) {
            logger.warn('nhnkcp order detected but pg_payment_data missing');
            return response;
        }

        logger.info('intercepted order create response — opening PG popup');

        // 주문 세금 정보 추출 — PC 결제창의 복합과세 분할 필드 전달용
        const orderTax = data.order
            ? {
                tax_amount: data.order.total_tax_amount ?? 0,
                vat_amount: data.order.total_vat_amount ?? 0,
                tax_free_amount: data.order.total_tax_free_amount ?? 0,
            }
            : {};

        // 간편결제가 아닌 경우 요청 body의 pay_method를 pgPaymentData에 주입
        const enrichedPgPaymentData = (!originalPaymentMethod && modifiedInit?.body && typeof modifiedInit.body === 'string')
            ? (() => {
                const pm = extractPaymentMethodFromBody(modifiedInit!.body as string);
                return pm ? { ...pgPaymentData, pay_method: pm, ...orderTax } : { ...pgPaymentData, ...orderTax };
            })()
            : { ...pgPaymentData, ...orderTax };

        void requestPaymentHandler({
            // eslint-disable-next-line @typescript-eslint/no-explicit-any
            params: {
                pgPaymentData: enrichedPgPaymentData as any,
                paymentMethod: originalPaymentMethod,
            },
        });

        const mutatedBody: OrderCreateResponseBody = {
            ...body,
            data: {
                ...data,
                requires_pg_payment: false,
                redirect_url: buildNoOpRedirectUrl(),
            },
        };

        return mutateResponse(response, mutatedBody);
    };

    logger.info('order response interceptor installed');
}
