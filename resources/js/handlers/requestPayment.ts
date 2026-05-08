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
    paymentMethod?: string;
}

interface ClientConfig {
    client_id: string;
    easy_pay_client_id?: string;
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
    card:            '100000000000', // 신용카드
    bank_transfer:   '010000000000', // 계좌이체
    virtual_account: '001000000000', // 가상계좌
    mobile:          '000010000000', // 휴대폰결제
    bank:            '010000000000',
    vbank:           '001000000000',
    phone:           '000010000000',
};

// KCP 간편결제 direct 파라미터 (payplus_web.jsp 규격)
// 간편결제는 pay_method="100000000000"(카드)로 고정하고, 결제수단은 direct 파라미터로 지정한다.
// 네이버페이 포인트: naverpay_direct + naverpay_point_direct 둘 다 필요
const KCP_EASY_PAY_DIRECT: Record<string, Record<string, string>> = {
    nhnkcp_payco:          { payco_direct: 'Y' },
    nhnkcp_naverpay:       { naverpay_direct: 'Y' },
    nhnkcp_naverpay_point: { naverpay_direct: 'Y', naverpay_point_direct: 'Y' },
    nhnkcp_kakaopay:       { kakaopay_direct: 'A' },
    nhnkcp_applepay:       { applepay_direct: 'Y' },
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
    const { pgPaymentData, paymentMethod: paramPaymentMethod } = (action.params || {}) as RequestPaymentParams;

    if (!pgPaymentData) {
        console.error('[sirsoft-pay_nhnkcp] pgPaymentData is required');
        return;
    }

    const paymentMethod = paramPaymentMethod ?? pgPaymentData.pay_method ?? 'card';
    const isEasyPay = typeof paymentMethod === 'string' && paymentMethod.startsWith('nhnkcp_');

    const G7Core = (window as any).G7Core;

    try {
        // 1. Client Config API 호출
        const configJson = await G7Core.api.get('/modules/sirsoft-ecommerce/payments/client-config/nhnkcp');

        if (!configJson.data) {
            console.error('[sirsoft-pay_nhnkcp] Failed to fetch client config', configJson);
            return;
        }

        const config: ClientConfig = configJson.data;
        const callbackUrl = window.location.origin + config.callback_urls.callback;

        // 간편결제는 pay_method 항상 "100000000000" (카드 비트마스크), direct 파라미터로 수단 지정
        const payMethod = isEasyPay
            ? '100000000000'
            : (KCP_PAY_METHOD[pgPaymentData.pay_method ?? 'card'] ?? '100000000000');

        // 간편결제는 테스트용 별도 site_cd(S6729) 사용 (레거시 settle_kcp.inc.php 동일 패턴)
        const siteCd = isEasyPay ? (config.easy_pay_client_id ?? config.client_id) : config.client_id;

        const fields: Record<string, string> = {
            site_cd: siteCd,
            ordr_idxx: pgPaymentData.order_number,
            good_name: pgPaymentData.order_name,
            good_mny: String(pgPaymentData.amount),
            buyr_name: pgPaymentData.customer_name ?? '',
            buyr_mail: pgPaymentData.customer_email ?? '',
            buyr_tel1: pgPaymentData.customer_phone ?? '',
            pay_method: payMethod,
            Ret_URL: callbackUrl,
        };

        // 간편결제 종류별 direct 파라미터 추가
        if (isEasyPay) {
            const directFields = KCP_EASY_PAY_DIRECT[paymentMethod];
            if (directFields) {
                Object.assign(fields, directFields);
            }
        }

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
    window.__kcpReady && window.__kcpReady();
  } else {
    window.__kcpFail && window.__kcpFail(new Error('KCP_Pay_Execute not defined'));
  }
} catch(e) {
  window.__kcpFail && window.__kcpFail(e);
}
<\/script>
</body></html>`);
            iframeDoc.close();

            // SDK 로드 실패 대비 타임아웃 — 결제창이 열리면(__kcpReady) 즉시 해제됨
            const sdkLoadTimer = setTimeout(() => {
                cleanup();
                reject(new Error('KCP SDK load timeout'));
            }, 15000);

            iframeWin.__kcpReady = () => clearTimeout(sdkLoadTimer);
        });

    } catch (error: unknown) {
        if (error instanceof KcpCancelledError) {
            G7Core?.state?.setLocal?.({ isSubmittingOrder: false, paymentMethod });
            return;
        }

        console.error('[sirsoft-pay_nhnkcp] requestPayment error', error);
        const errorMessage = error instanceof Error ? error.message : 'Unknown error';
        G7Core?.state?.setLocal?.({ paymentErrorMessage: errorMessage, isSubmittingOrder: false, paymentMethod });
        G7Core?.modal?.open?.('nhnkcp_payment_error_modal');
    }
}
