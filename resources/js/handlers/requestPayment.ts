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

// 사용자가 결제창을 직접 닫은 경우 - 에러 모달 없이 조용히 처리
class KcpCancelledError extends Error {
    constructor(msg?: string) {
        super(msg ?? '결제가 취소되었습니다.');
        this.name = 'KcpCancelledError';
    }
}

// KCP pay_method 비트마스크 변환 (payplus_web.jsp 규격)
const KCP_PAY_METHOD: Record<string, string> = {
    card:  '100000000000',
    bank:  '010000000000',
    vbank: '001000000000',
    phone: '000100000000',
};

/**
 * NHN KCP 결제창 호출 핸들러
 *
 * Chrome은 비동기로 로드된 외부 스크립트에서 document.write()를 차단합니다.
 * KCP payplus_web.jsp는 내부적으로 document.write()를 사용하므로,
 * iframe 내에서 동기 파싱 방식(document.open/write/close)으로 SDK를 로드합니다.
 *
 * 흐름:
 *   1. Client Config API → site_cd, sdk_url 획득
 *   2. 메인 창에 반투명 dim 오버레이 추가
 *   3. 투명 iframe 생성 → document.open/write/close로 SDK 동기 로드
 *   4. iframe 내 KCP_Pay_Execute() 호출 → 결제창 오픈
 *   5. m_Completepayment 콜백에서 res_cd 확인
 *      - 성공(0000): Ret_URL로 POST → 완료 페이지 이동
 *      - 취소/오류: iframe+overlay 제거, 결제 상태 복원
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
            // 기존 요소 정리
            document.getElementById('kcp-sdk-iframe')?.remove();
            document.getElementById('kcp-dim-overlay')?.remove();

            // 2. 메인 창에 반투명 dim 오버레이 (결제창 뒤 배경)
            const overlay = document.createElement('div');
            overlay.id = 'kcp-dim-overlay';
            overlay.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.6);z-index:99998;';
            document.body.appendChild(overlay);

            // 3. 투명 iframe - KCP 결제창이 내부에 렌더링됨
            const iframe = document.createElement('iframe');
            iframe.id = 'kcp-sdk-iframe';
            iframe.setAttribute('allowtransparency', 'true');
            iframe.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;border:0;z-index:99999;background:transparent;';
            document.body.appendChild(iframe);

            const iframeWin = iframe.contentWindow as any;

            const cleanup = () => {
                iframe.remove();
                overlay.remove();
            };

            // 5. 결제 완료 콜백 - KCP가 결제 후 호출
            iframeWin.m_Completepayment = function (form: HTMLFormElement) {
                const resCode = (form.elements as any)['res_cd']?.value ?? '';
                const resMsg  = (form.elements as any)['res_msg']?.value ?? '';

                if (resCode === '0000') {
                    // 결제 성공 → Ret_URL로 POST 제출 (top window 이동)
                    form.action = callbackUrl;
                    form.method = 'POST';
                    form.target = '_top';
                    iframeWin.document.body.appendChild(form);
                    form.submit();
                } else {
                    // 취소 또는 오류
                    cleanup();
                    const isCancelled = resCode === '' || resCode === '7777' || resMsg.includes('취소');
                    reject(isCancelled ? new KcpCancelledError(resMsg) : new Error(`KCP 오류 [${resCode}]: ${resMsg}`));
                }
            };

            // __kcpDone은 사용하지 않음 — Promise는 m_Completepayment 또는 timeout까지 pending 유지
            // (resolve를 즉시 호출하면 이후 cancel 시 reject가 무시됨)
            iframeWin.__kcpFail = (err: Error) => {
                cleanup();
                reject(err);
            };

            // 4. SDK 동기 로드 + KCP_Pay_Execute 호출
            const iframeDoc = (iframe.contentDocument || iframeWin.document) as Document;
            iframeDoc.open();
            iframeDoc.write(`<!DOCTYPE html><html><head>
<script src="${config.sdk_url}"><\/script>
</head><body style="margin:0;padding:0;background:transparent;">
<form name="order_info">${hiddenInputs}</form>
<script>
try {
  if (typeof KCP_Pay_Execute === 'function') {
    KCP_Pay_Execute(document.forms['order_info']);
  } else {
    window.__kcpFail && window.__kcpFail(new Error('KCP_Pay_Execute not defined'));
  }
} catch(e) {
  window.__kcpFail && window.__kcpFail(e);
}
<\/script>
</body></html>`);
            iframeDoc.close();

            // SDK 로드 타임아웃
            setTimeout(() => {
                cleanup();
                reject(new Error('KCP SDK load timeout'));
            }, 15000);
        });

    } catch (error: unknown) {
        if (error instanceof KcpCancelledError) {
            // 사용자가 결제 취소 → 에러 모달 없이 조용히 상태 복원
            G7Core?.state?.setLocal?.({ isSubmittingOrder: false });
            return;
        }

        console.error('[sirsoft-pay-nhnkcp] requestPayment error', error);
        const errorMessage = error instanceof Error ? error.message : 'Unknown error';
        G7Core?.state?.setLocal?.({ paymentErrorMessage: errorMessage, isSubmittingOrder: false });
        G7Core?.modal?.open?.('nhnkcp_payment_error_modal');
    }
}
