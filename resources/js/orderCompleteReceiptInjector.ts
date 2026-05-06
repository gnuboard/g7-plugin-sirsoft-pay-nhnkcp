const PLUGIN_ID = 'sirsoft-pay-nhnkcp';
const FLAG = '__kcpOcReceiptInjectorInstalled';
const BTN_ID = 'kcp-oc-receipt-btn';

const ORDER_COMPLETE_RE = /^\/shop\/orders\/([^/]+)\/complete$/;
const MYPAGE_ORDER_RE = /^\/mypage\/orders\/([^/]+)$/;

type Payment = {
    pg_provider: string;
    transaction_id: string | null;
    [key: string]: unknown;
};

function getToken(): string | null {
    return localStorage.getItem('auth_token');
}

async function fetchPayment(orderNumber: string): Promise<Payment | null> {
    const token = getToken();
    if (!token) return null;

    try {
        const res = await fetch(`/api/modules/sirsoft-ecommerce/user/orders/${orderNumber}`, {
            headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
        });
        if (!res.ok) return null;
        const data = (await res.json()) as { data?: { payment?: Payment } };
        return data?.data?.payment ?? null;
    } catch {
        return null;
    }
}

async function fetchReceiptUrl(orderNumber: string): Promise<{ receipt_url: string; cash_receipt_url: string | null } | null> {
    const token = getToken();
    if (!token) return null;

    try {
        const res = await fetch(`/api/plugins/${PLUGIN_ID}/user/orders/${orderNumber}/receipt`, {
            headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
        });
        if (!res.ok) return null;
        return (await res.json()) as { receipt_url: string; cash_receipt_url: string | null };
    } catch {
        return null;
    }
}

function makeBtn(text: string, classes: string, onClick: (btn: HTMLButtonElement) => void): HTMLButtonElement {
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = classes;
    btn.textContent = text;
    btn.addEventListener('click', () => onClick(btn));
    return btn;
}

// 주문완료 페이지 버튼 영역에 영수증 버튼 주입
async function injectOnOrderComplete(orderNumber: string): Promise<void> {
    if (document.getElementById(BTN_ID)) return;

    const payment = await fetchPayment(orderNumber);
    if (!payment || payment.pg_provider !== 'nhnkcp' || !payment.transaction_id) return;

    // "주문 상세 보기" 버튼 찾기 (bg-blue-600)
    const blueBtn = Array.from(document.querySelectorAll<HTMLButtonElement>('button[type="button"]'))
        .find(b => b.className.includes('bg-blue-600'));

    if (!blueBtn?.parentElement) return;

    const container = blueBtn.parentElement;

    const receiptBtn = makeBtn('영수증 조회', blueBtn.className.replace(/bg-blue-\d+/g, 'bg-green-600').replace(/hover:bg-blue-\d+/g, 'hover:bg-green-700'), async (btn) => {
        btn.disabled = true;
        btn.textContent = '로딩 중...';
        const data = await fetchReceiptUrl(orderNumber);
        btn.disabled = false;
        btn.textContent = '영수증 조회';
        if (data?.receipt_url) window.open(data.receipt_url, '_blank', 'noopener,noreferrer');
    });
    receiptBtn.id = BTN_ID;

    // "쇼핑 계속하기" 버튼 앞에 삽입
    const lastBtn = container.lastElementChild;
    container.insertBefore(receiptBtn, lastBtn);

    // 현금영수증 버튼 (가상계좌/계좌이체)
    const cashBtnId = 'kcp-oc-cash-receipt-btn';
    if (!document.getElementById(cashBtnId) && (payment.payment_method === 'vbank' || payment.payment_method === 'bank_transfer')) {
        const cashBtn = makeBtn('현금영수증 조회', receiptBtn.className.replace(/bg-green-\d+/g, 'bg-teal-600').replace(/hover:bg-green-\d+/g, 'hover:bg-teal-700'), async (btn) => {
            btn.disabled = true;
            btn.textContent = '로딩 중...';
            const data = await fetchReceiptUrl(orderNumber);
            btn.disabled = false;
            btn.textContent = '현금영수증 조회';
            if (data?.cash_receipt_url) window.open(data.cash_receipt_url, '_blank', 'noopener,noreferrer');
        });
        cashBtn.id = cashBtnId;
        container.insertBefore(cashBtn, lastBtn);
    }

    console.info(`[${PLUGIN_ID}] receipt button injected on order complete page`);
}

// 마이페이지 주문상세 페이지 영수증 영역 주입
async function injectOnMypageOrder(orderNumber: string): Promise<void> {
    const existingId = 'kcp-mp-receipt-row';
    if (document.getElementById(existingId)) return;

    const payment = await fetchPayment(orderNumber);
    if (!payment || payment.pg_provider !== 'nhnkcp' || !payment.transaction_id) return;

    // 결제수단, 카드명 등 label+value 행이 있는 dl/dd/p 영역 찾기
    // "결제" 텍스트가 포함된 행들을 탐색해서 마지막 행 다음에 주입
    const PAYMENT_LABEL_TEXTS = new Set(['결제수단', '결제방법', '결제 방법', '카드번호', '결제수단/카드정보']);
    const paymentLabels = Array.from(document.querySelectorAll('span, dt, td, p'))
        .filter(el => PAYMENT_LABEL_TEXTS.has(el.textContent?.trim() ?? ''));

    let insertAfter: Element | null = null;
    if (paymentLabels.length > 0) {
        const last = paymentLabels[paymentLabels.length - 1];
        // 행 컨테이너 (부모의 부모)
        insertAfter = last.closest('[class*="flex"]') ?? last.parentElement;
    }

    const row = document.createElement('div');
    row.id = existingId;
    row.className = 'flex items-center justify-between pt-3 mt-2 border-t border-gray-200';
    row.innerHTML = `<span class="text-sm text-gray-500">영수증</span><div class="flex gap-2"></div>`;

    const btnArea = row.querySelector('div')!;

    const receiptBtn = makeBtn('영수증 조회', 'inline-flex items-center gap-1 text-sm text-blue-600 hover:underline', async (btn) => {
        btn.disabled = true;
        btn.textContent = '로딩 중...';
        const data = await fetchReceiptUrl(orderNumber);
        btn.disabled = false;
        btn.textContent = '영수증 조회';
        if (data?.receipt_url) window.open(data.receipt_url, '_blank', 'noopener,noreferrer');
    });
    btnArea.appendChild(receiptBtn);

    if (payment.payment_method === 'vbank' || payment.payment_method === 'bank_transfer') {
        const cashBtn = makeBtn('현금영수증 조회', 'inline-flex items-center gap-1 text-sm text-green-600 hover:underline', async (btn) => {
            btn.disabled = true;
            btn.textContent = '로딩 중...';
            const data = await fetchReceiptUrl(orderNumber);
            btn.disabled = false;
            btn.textContent = '현금영수증 조회';
            if (data?.cash_receipt_url) window.open(data.cash_receipt_url, '_blank', 'noopener,noreferrer');
        });
        btnArea.appendChild(cashBtn);
    }

    if (insertAfter?.parentElement) {
        insertAfter.parentElement.insertBefore(row, insertAfter.nextSibling);
    } else {
        // 삽입 위치를 못 찾으면 main_content_area 끝에 추가
        document.getElementById('main_content')?.appendChild(row);
    }

    console.info(`[${PLUGIN_ID}] receipt row injected on mypage order page`);
}

function tryInject(): void {
    const path = location.pathname;
    const ocMatch = path.match(ORDER_COMPLETE_RE);
    if (ocMatch) {
        void injectOnOrderComplete(ocMatch[1]);
        return;
    }
    const mpMatch = path.match(MYPAGE_ORDER_RE);
    if (mpMatch) {
        void injectOnMypageOrder(mpMatch[1]);
    }
}

export function installOrderCompleteReceiptInjector(): void {
    if (typeof window === 'undefined') return;
    const w = window as Record<string, unknown>;
    if (w[FLAG]) return;
    w[FLAG] = true;

    console.info(`[${PLUGIN_ID}] order complete receipt injector installed`);

    const schedule = (delay = 1200) => setTimeout(tryInject, delay);

    // 현재 페이지 즉시 처리
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => schedule());
    } else {
        schedule();
    }

    // SPA 네비게이션 감지
    const origPush = history.pushState.bind(history);
    history.pushState = (...args: Parameters<typeof history.pushState>) => {
        origPush(...args);
        schedule();
    };
    window.addEventListener('popstate', () => schedule(500));
}
