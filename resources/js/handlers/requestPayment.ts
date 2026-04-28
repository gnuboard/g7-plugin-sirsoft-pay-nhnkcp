/* eslint-disable @typescript-eslint/no-explicit-any */

interface PgPaymentData {
    order_number: string;
    order_name: string;
    amount: number;
    currency?: string;
    customer_name?: string;
    customer_email?: string;
    customer_phone?: string;
}

interface RequestPaymentParams {
    pgPaymentData: PgPaymentData;
}

interface ClientConfig {
    client_id: string;
    sdk_url: string;
    callback_urls: {
        callback: string;
    };
}

declare global {
    interface Window {
        m_Completepayment: (form: HTMLFormElement) => void;
        KCP_Pay_Execute: (form: HTMLFormElement) => void;
    }
}

function loadScript(src: string): Promise<void> {
    return new Promise((resolve, reject) => {
        if (document.querySelector(`script[src="${src}"]`)) {
            resolve();
            return;
        }

        const script = document.createElement('script');
        script.src = src;
        script.async = false; // KCP SDK must load synchronously to register globals
        script.onload = () => resolve();
        script.onerror = () => reject(new Error(`Failed to load script: ${src}`));
        document.head.appendChild(script);
    });
}

/**
 * NHN KCP 결제창 호출 핸들러
 *
 * 체크아웃 레이아웃에서 주문 생성 API 성공 후 호출됩니다:
 *   handler: "sirsoft-pay-nhnkcp.requestPayment"
 *   params: { pgPaymentData: response.data.pg_payment_data }
 *
 * 호출 순서:
 *   1. Client Config API 호출 → site_cd, sdk_url 획득
 *   2. window.m_Completepayment 정의 (payplus.js 로드 전 반드시 선언)
 *   3. KCP payplus.js 동적 로드
 *   4. 결제 폼 생성 후 KCP_Pay_Execute() 호출 → 결제창 오픈
 *   5. 결제 완료 시 KCP가 Ret_URL(POST)로 결과 전달
 */
export async function requestPaymentHandler(action: any, _context?: any): Promise<void> {
    const { pgPaymentData } = (action.params || {}) as RequestPaymentParams;

    if (!pgPaymentData) {
        console.error('[sirsoft-pay-nhnkcp] pgPaymentData is required');
        return;
    }

    const G7Core = (window as any).G7Core;

    try {
        // 1. Client Config API 호출
        const configJson = await G7Core.api.get('/modules/sirsoft-ecommerce/payments/client-config/nhnkcp');

        if (!configJson.data) {
            console.error('[sirsoft-pay-nhnkcp] Failed to fetch client config', configJson);
            return;
        }

        const config: ClientConfig = configJson.data;
        const callbackUrl = window.location.origin + config.callback_urls.callback;

        // 2. m_Completepayment 반드시 payplus.js 로드 전에 선언
        window.m_Completepayment = function (form: HTMLFormElement) {
            // KCP가 결제 완료 후 이 함수를 호출합니다.
            // form 데이터(enc_data, enc_info, tno 등)를 서버로 POST합니다.
            form.action = callbackUrl;
            form.method = 'POST';
            document.body.appendChild(form);
            form.submit();
        };

        // 3. KCP payplus.js 동적 로드
        await loadScript(config.sdk_url);

        if (typeof window.KCP_Pay_Execute !== 'function') {
            await new Promise<void>((resolve) => setTimeout(resolve, 200));
        }

        if (typeof window.KCP_Pay_Execute !== 'function') {
            console.error('[sirsoft-pay-nhnkcp] KCP_Pay_Execute not available after loading SDK');
            return;
        }

        // 4. 결제 폼 생성
        const form = document.createElement('form');
        form.name = 'order_info';

        const fields: Record<string, string> = {
            site_cd: config.client_id,
            ordr_idxx: pgPaymentData.order_number,
            good_name: pgPaymentData.order_name,
            good_mny: String(pgPaymentData.amount),
            buyr_name: pgPaymentData.customer_name ?? '',
            buyr_mail: pgPaymentData.customer_email ?? '',
            buyr_tel1: pgPaymentData.customer_phone ?? '',
            pay_method: 'CARD',
            Ret_URL: callbackUrl,
        };

        for (const [name, value] of Object.entries(fields)) {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = name;
            input.value = value;
            form.appendChild(input);
        }

        document.body.appendChild(form);

        // 5. KCP 결제창 호출
        window.KCP_Pay_Execute(form);

    } catch (error: unknown) {
        console.error('[sirsoft-pay-nhnkcp] requestPayment error', error);

        const errorMessage = error instanceof Error ? error.message : 'Unknown error';
        G7Core?.state?.setLocal?.({ paymentErrorMessage: errorMessage, isSubmittingOrder: false });
        G7Core?.modal?.open?.('nhnkcp_payment_error_modal');
    }
}
