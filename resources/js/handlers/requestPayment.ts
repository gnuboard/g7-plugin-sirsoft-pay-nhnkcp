/* eslint-disable @typescript-eslint/no-explicit-any */

interface PgPaymentData {
    order_number: string;
    order_name: string;
    amount: number;
    currency?: string;
    pay_method?: string;
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

        // 2. KCP SDK를 iframe 동기 파싱으로 로드 후 iframe 내에서 결제창 호출
        //    - iframe document.open/write/close: 파싱 중 script 삽입 → document.write 정상 동작
        //    - m_Completepayment: iframe window에 등록, 결제 완료 후 콜백 URL POST
        //    - 결제 후 top window를 완료 페이지로 이동 (parent.location)
        // KCP pay_method 비트마스크 변환 (payplus_web.jsp 규격)
        // card=100000000000, bank=010000000000, vbank=001000000000, phone=000100000000
        const KCP_PAY_METHOD: Record<string, string> = {
            card:  '100000000000',
            bank:  '010000000000',
            vbank: '001000000000',
            phone: '000100000000',
        };
        const payMethod = KCP_PAY_METHOD[pgPaymentData.pay_method ?? 'card'] ?? '100000000000';

        const fields: Record<string, string> = {
            site_cd: config.client_id,
            ordr_idxx: pgPaymentData.order_number,
            good_name: pgPaymentData.order_name,
            good_mny: String(pgPaymentData.amount),
            buyr_name: pgPaymentData.customer_name ?? '',
            buyr_mail: pgPaymentData.customer_email ?? '',
            buyr_tel1: pgPaymentData.customer_phone ?? '',
            pay_method: payMethod,
            Ret_URL: callbackUrl,
        };

        const hiddenInputs = Object.entries(fields)
            .map(([n, v]) => `<input type="hidden" name="${n}" value="${v.replace(/"/g, '&quot;')}">`)
            .join('');

        await new Promise<void>((resolve, reject) => {
            const existingIframe = document.getElementById('kcp-sdk-iframe') as HTMLIFrameElement | null;
            if (existingIframe) existingIframe.remove();

            // KCP 결제창은 iframe 내부에 오버레이로 렌더링되므로
            // iframe을 전체화면 fixed 오버레이로 표시해야 사용자가 볼 수 있음
            const iframe = document.createElement('iframe');
            iframe.id = 'kcp-sdk-iframe';
            iframe.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;border:0;z-index:99999;background:#fff;';
            document.body.appendChild(iframe);

            const iframeWin = iframe.contentWindow as any;

            // iframe 내 결제 완료 콜백 → top window POST 이동
            iframeWin.m_Completepayment = function (form: HTMLFormElement) {
                form.action = callbackUrl;
                form.method = 'POST';
                form.target = '_top';
                iframeWin.document.body.appendChild(form);
                form.submit();
            };

            iframeWin.__kcpDone = resolve;
            iframeWin.__kcpFail = (err: Error) => {
                iframe.remove();
                reject(err);
            };

            const iframeDoc = (iframe.contentDocument || iframeWin.document) as Document;
            iframeDoc.open();
            iframeDoc.write(`<!DOCTYPE html><html><head>
<script src="${config.sdk_url}"><\/script>
</head><body style="margin:0;padding:0;">
<form name="order_info">${hiddenInputs}</form>
<script>
try {
  if (typeof KCP_Pay_Execute === 'function') {
    KCP_Pay_Execute(document.forms['order_info']);
    window.__kcpDone && window.__kcpDone();
  } else {
    window.__kcpFail && window.__kcpFail(new Error('KCP_Pay_Execute not defined'));
  }
} catch(e) {
  window.__kcpFail && window.__kcpFail(e);
}
<\/script>
</body></html>`);
            iframeDoc.close();

            setTimeout(() => {
                iframe.remove();
                reject(new Error('KCP SDK load timeout'));
            }, 15000);
        });

    } catch (error: unknown) {
        console.error('[sirsoft-pay-nhnkcp] requestPayment error', error);

        const errorMessage = error instanceof Error ? error.message : 'Unknown error';
        G7Core?.state?.setLocal?.({ paymentErrorMessage: errorMessage, isSubmittingOrder: false });
        G7Core?.modal?.open?.('nhnkcp_payment_error_modal');
    }
}
