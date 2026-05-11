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

// KCP pay_method 비트마스크 변환 (PC: payplus_web.jsp 규격)
const KCP_PAY_METHOD: Record<string, string> = {
    card:            '100000000000', // 신용카드
    bank_transfer:   '010000000000', // 계좌이체
    virtual_account: '001000000000', // 가상계좌
    mobile:          '000010000000', // 휴대폰결제
    bank:            '010000000000',
    vbank:           '001000000000',
    phone:           '000010000000',
};

// KCP 간편결제 direct 파라미터 (PC/모바일 공통)
const KCP_EASY_PAY_DIRECT: Record<string, Record<string, string>> = {
    nhnkcp_payco:          { payco_direct: 'Y' },
    nhnkcp_naverpay:       { naverpay_direct: 'Y' },
    nhnkcp_naverpay_point: { naverpay_direct: 'Y', naverpay_point_direct: 'Y' },
    nhnkcp_kakaopay:       { kakaopay_direct: 'A' },
    nhnkcp_applepay:       { applepay_direct: 'Y' },
};

/**
 * 모바일 기기 여부 판별 (3단계 fallback)
 *
 * 1) User Agent Client Hints — 브라우저가 직접 판단 (Chrome/Edge 90+)
 * 2) UA 문자열 파싱
 * 3) iPadOS 등 데스크탑 UA를 보내는 터치 기기 판별 (maxTouchPoints > 1)
 */
function isMobileDevice(): boolean {
    if (typeof navigator === 'undefined') return false;

    const nav = navigator as Navigator & { userAgentData?: { mobile: boolean } };
    if (nav.userAgentData?.mobile !== undefined) {
        return nav.userAgentData.mobile;
    }

    const ua = (navigator.userAgent || '').toLowerCase();
    if (/android|iphone|ipod|windows phone|iemobile|blackberry|opera mini|mobile safari/.test(ua)) {
        return true;
    }

    // iPadOS, Android 태블릿 등 — 터치스크린 노트북은 maxTouchPoints=1이므로 >1 조건 필요
    const touchPoints = (navigator as Navigator & { maxTouchPoints?: number }).maxTouchPoints ?? 0;
    if (touchPoints > 1 && !ua.includes('windows') && !ua.includes('macintosh')) {
        return true;
    }

    return false;
}

/**
 * NHN KCP 결제창 호출 핸들러
 *
 * - 모바일: SOAP approval_key 획득 → form POST(페이지 전환) → 기존 authCallback 처리
 * - PC: payplus_web.jsp SDK를 iframe 내 동기 로드 → KCP_Pay_Execute() → 콜백
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

        if (isMobileDevice()) {
            await handleMobilePayment(G7Core, pgPaymentData, paymentMethod, isEasyPay, callbackUrl);
        } else {
            await handlePcPayment(config, pgPaymentData, paymentMethod, isEasyPay, callbackUrl);
        }

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

/**
 * 모바일 결제 흐름
 *
 * 1) 서버에서 SOAP으로 approval_key + pay_url 획득
 * 2) 전체 form fields를 받아 브라우저가 pay_url 로 POST 전환 (페이지 이동)
 * 3) KCP가 결제 완료 후 Ret_URL(authCallback)로 redirect → 기존 서버 처리
 */
async function handleMobilePayment(
    G7Core: any,
    pgPaymentData: PgPaymentData,
    paymentMethod: string,
    isEasyPay: boolean,
    callbackUrl: string,
): Promise<void> {
    const approvalJson = await G7Core.api.post('/plugins/sirsoft-pay_nhnkcp/mobile/approval-key', {
        order_number: pgPaymentData.order_number,
        amount: pgPaymentData.amount,
        good_name: pgPaymentData.order_name,
        pay_method: paymentMethod,
        buyr_name: pgPaymentData.customer_name ?? '',
        buyr_mail: pgPaymentData.customer_email ?? '',
        buyr_tel1: pgPaymentData.customer_phone ?? '',
        ret_url: callbackUrl,
    });

    if (!approvalJson.success || !approvalJson.data) {
        throw new Error(approvalJson.error ?? 'KCP 모바일 승인키 획득 실패');
    }

    const { pay_url, fields } = approvalJson.data as { pay_url: string; fields: Record<string, string> };

    // 페이지 전환 form 생성 및 제출
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = pay_url;
    form.acceptCharset = 'euc-kr'; // KCP 모바일은 EUC-KR 인코딩 필요
    form.style.display = 'none';

    for (const [name, value] of Object.entries(fields)) {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        input.value = String(value);
        form.appendChild(input);
    }

    document.body.appendChild(form);
    form.submit();

    // 페이지가 pay_url 로 이동하므로 Promise는 자연스럽게 중단됨
    await new Promise<never>(() => {});
}

/**
 * PC 결제 흐름
 *
 * Chrome 비동기 document.write() 차단을 우회하기 위해 iframe 내에서
 * document.open/write/close 로 SDK를 동기 로드한 뒤 KCP_Pay_Execute() 호출.
 */
async function handlePcPayment(
    config: ClientConfig,
    pgPaymentData: PgPaymentData,
    paymentMethod: string,
    isEasyPay: boolean,
    callbackUrl: string,
): Promise<void> {
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

        // 메인 창에 반투명 dim 오버레이 (결제창 뒤 배경)
        const overlay = document.createElement('div');
        overlay.id = 'kcp-dim-overlay';
        overlay.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.6);z-index:99998;';
        document.body.appendChild(overlay);

        // 투명 iframe - KCP 결제창이 내부에 렌더링됨
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

        // 결제 완료 콜백 - KCP가 결제 후 호출
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

        // SDK 동기 로드 + KCP_Pay_Execute 호출
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
}
