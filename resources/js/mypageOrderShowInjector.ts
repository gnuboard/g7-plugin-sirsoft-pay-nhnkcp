const PLUGIN_ID = 'sirsoft-pay_nhnkcp';
const FLAG = '__kcpMpShowInjectorInstalled';
const VBANK_ID = 'kcp-mp-vbank-row';
const ROW_ID = 'kcp-mp-receipt-row';

const ORDER_SHOW_RE = /^\/mypage\/orders\/([^/]+)$/;

interface Payment {
    pg_provider?: string;
    payment_method?: string;
    transaction_id?: string | null;
    vbank_name?: string;
    vbank_number?: string;
    vbank_holder?: string;
    due_date_formatted?: string;
    [key: string]: unknown;
}

interface OrderData {
    order_number?: string;
    total_amount_formatted?: string;
    payment?: Payment;
}

function getOrderFromState(orderNumber: string): OrderData | null {
    try {
        const g7 = (window as unknown as Record<string, unknown>).G7Core as Record<string, unknown> | undefined;
        const getState = g7?.getState as (() => Record<string, unknown>) | undefined;
        const ctx = getState?.()?.currentDataContext as Record<string, unknown> | undefined;
        const order = ctx?.order as { data?: OrderData } | undefined;
        const data = order?.data;
        if (!data || data.order_number !== orderNumber) return null;
        return data;
    } catch {
        return null;
    }
}

function getToken(): string | null {
    return localStorage.getItem('auth_token');
}

async function fetchReceiptUrls(orderNumber: string): Promise<{ receipt_url?: string; cash_receipt_url?: string } | null> {
    const token = getToken();
    if (!token) return null;
    try {
        const res = await fetch(`/api/plugins/${PLUGIN_ID}/user/orders/${orderNumber}/receipt`, {
            headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
        });
        if (!res.ok) return null;
        return (await res.json()) as { receipt_url?: string; cash_receipt_url?: string };
    } catch {
        return null;
    }
}

function findPaymentRowsContainer(): Element | null {
    const panel = document.getElementById('order_payment_info_panel');
    if (panel) {
        const spaceY = Array.from(panel.children).find(el => el.className?.includes('space-y'));
        return spaceY ?? panel;
    }

    // "결제 정보" 헤딩 탐색
    const h3 = Array.from(document.querySelectorAll<HTMLElement>('h3')).find(
        el => el.textContent?.includes('결제 정보'),
    );
    if (!h3) return null;

    const panelDiv = h3.parentElement?.parentElement;
    if (!panelDiv) return null;

    const spaceY = Array.from(panelDiv.children).find(el => el.className?.includes('space-y'));
    return spaceY ?? panelDiv;
}

// 라벨 + 값 행 생성 헬퍼
function makeInfoRow(label: string, value: string, valueClass = 'font-medium text-gray-900 dark:text-white'): HTMLElement {
    const row = document.createElement('div');
    row.className = 'flex items-center justify-between text-sm';

    const lbl = document.createElement('span');
    lbl.className = 'text-gray-500 dark:text-gray-400';
    lbl.textContent = label;

    const val = document.createElement('span');
    val.className = valueClass;
    val.textContent = value;

    row.appendChild(lbl);
    row.appendChild(val);
    return row;
}

function buildVbankInfoBlock(payment: Payment, totalAmountFormatted: string): HTMLElement {
    const wrap = document.createElement('div');
    wrap.id = VBANK_ID;
    wrap.className = 'pt-4 mt-2 border-t border-gray-200 dark:border-gray-700 space-y-3';

    const title = document.createElement('p');
    title.className = 'text-sm font-semibold text-gray-800 dark:text-gray-200';
    title.textContent = '가상계좌 입금 안내';

    const rows = document.createElement('div');
    rows.className = 'space-y-1.5';
    rows.appendChild(makeInfoRow('은행', payment.vbank_name ?? ''));
    rows.appendChild(makeInfoRow('계좌번호', payment.vbank_number ?? '', 'font-medium text-gray-900 dark:text-white font-mono tracking-wide'));
    rows.appendChild(makeInfoRow('예금주', payment.vbank_holder ?? ''));
    rows.appendChild(makeInfoRow('입금 금액', totalAmountFormatted));
    if (payment.due_date_formatted) {
        rows.appendChild(makeInfoRow('입금 기한', payment.due_date_formatted));
    }

    const notice = document.createElement('p');
    notice.className = 'text-xs text-amber-600 dark:text-amber-400';
    notice.textContent = '입금 기한 내에 입금하지 않으시면 주문이 자동 취소됩니다.';

    wrap.appendChild(title);
    wrap.appendChild(rows);
    wrap.appendChild(notice);
    return wrap;
}

function buildReceiptRow(orderNumber: string): HTMLElement {
    const row = document.createElement('div');
    row.id = ROW_ID;
    row.className = 'pt-4 mt-2 border-t border-gray-200 dark:border-gray-700';

    const inner = document.createElement('div');
    inner.className = 'flex items-center justify-between';

    const label = document.createElement('span');
    label.className = 'text-gray-500 dark:text-gray-400 text-sm';
    label.textContent = '영수증';

    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'inline-flex items-center gap-1 text-sm text-blue-600 dark:text-blue-400 hover:underline disabled:opacity-50';
    btn.textContent = '영수증 조회';

    btn.addEventListener('click', async () => {
        btn.disabled = true;
        btn.textContent = '로딩 중...';
        const data = await fetchReceiptUrls(orderNumber);
        btn.disabled = false;
        btn.textContent = '영수증 조회';
        if (data) {
            const KcpPopup = (window as unknown as Record<string, unknown>).KcpReceiptPopup as
                (new (p: { url?: string; cash_url?: string }) => unknown) | undefined;
            if (KcpPopup) {
                new KcpPopup({ url: data.receipt_url, cash_url: data.cash_receipt_url });
            }
        }
    });

    inner.appendChild(label);
    inner.appendChild(btn);
    row.appendChild(inner);
    return row;
}

async function tryInject(orderNumber: string): Promise<boolean> {
    const orderData = getOrderFromState(orderNumber);
    if (!orderData) return false; // 데이터 로딩 중 → 재시도

    const { payment } = orderData;
    if (!payment || payment.pg_provider !== 'nhnkcp') return true; // nhnkcp 아님

    const needsVbank = payment.payment_method === 'vbank' && !!payment.vbank_name;
    const needsReceipt = !!payment.transaction_id;

    // 필요한 요소가 이미 모두 DOM에 존재하면 완료
    const vbankDone = !needsVbank || !!document.getElementById(VBANK_ID);
    const receiptDone = !needsReceipt || !!document.getElementById(ROW_ID);
    if (vbankDone && receiptDone) return true;

    const container = findPaymentRowsContainer();
    if (!container) return false; // DOM 미렌더링 → 재시도

    if (!document.getElementById(VBANK_ID) && needsVbank) {
        container.appendChild(buildVbankInfoBlock(payment, orderData.total_amount_formatted ?? ''));
        console.info(`[${PLUGIN_ID}] vbank info injected on mypage order show`);
        return false; // 다음 폴에서 DOM 잔존 여부 재확인
    }

    if (!document.getElementById(ROW_ID) && needsReceipt) {
        container.appendChild(buildReceiptRow(orderNumber));
        console.info(`[${PLUGIN_ID}] receipt button injected on mypage order show`);
        return false; // 다음 폴에서 DOM 잔존 여부 재확인
    }

    return true;
}

function startPolling(orderNumber: string): void {
    let attempts = 0;
    const id = setInterval(() => {
        attempts++;
        void tryInject(orderNumber).then(done => {
            if (done || attempts >= 30) clearInterval(id);
        });
    }, 400);
}

function onRouteChange(): void {
    const match = location.pathname.match(ORDER_SHOW_RE);
    if (match) startPolling(match[1]);
}

export function installMypageOrderShowInjector(): void {
    if (typeof window === 'undefined') return;
    const w = window as unknown as Record<string, unknown>;
    if (w[FLAG]) return;
    w[FLAG] = true;

    console.info(`[${PLUGIN_ID}] mypage order show injector installed`);

    const schedule = (delay = 1500) => setTimeout(onRouteChange, delay);

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => schedule());
    } else {
        schedule();
    }

    const origPush = history.pushState.bind(history);
    history.pushState = (...args: Parameters<typeof history.pushState>) => {
        origPush(...args);
        schedule(600);
    };
    window.addEventListener('popstate', () => schedule(500));
}
