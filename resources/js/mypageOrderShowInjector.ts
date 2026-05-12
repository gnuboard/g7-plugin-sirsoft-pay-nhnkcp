const PLUGIN_ID = 'sirsoft-pay_nhnkcp';
const FLAG = '__kcpMpShowInjectorInstalled';
const ROW_ID = 'kcp-mp-receipt-row';

const ORDER_SHOW_RE = /^\/mypage\/orders\/([^/]+)$/;

interface OrderData {
    order_number?: string;
    payment?: {
        pg_provider?: string;
        transaction_id?: string | null;
        [key: string]: unknown;
    };
}

function getOrderFromState(orderNumber: string): OrderData | null {
    try {
        const g7 = (window as Record<string, unknown>).G7Core as Record<string, unknown> | undefined;
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
    // 1순위: order_payment_info_panel ID (템플릿이 나중에 업데이트되면 extension이 처리하고 여기서는 skip)
    const panel = document.getElementById('order_payment_info_panel');
    if (panel) {
        const spaceY = Array.from(panel.children).find(el => el.className?.includes('space-y'));
        return spaceY ?? panel;
    }

    // 2순위: '결제 정보' 헤딩을 포함하는 패널 탐색
    const h3 = Array.from(document.querySelectorAll<HTMLElement>('h3')).find(
        el => el.textContent?.includes('결제 정보'),
    );
    if (!h3) return null;

    // h3 → 헤더 div(flex border-b) → 패널 div(rounded-lg)
    const panelDiv = h3.parentElement?.parentElement;
    if (!panelDiv) return null;

    const spaceY = Array.from(panelDiv.children).find(el => el.className?.includes('space-y'));
    return spaceY ?? panelDiv;
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
            const KcpPopup = (window as Record<string, unknown>).KcpReceiptPopup as
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
    // extension이 이미 주입했거나 이전 호출에서 주입된 경우 skip
    if (document.getElementById(ROW_ID)) return true;

    const orderData = getOrderFromState(orderNumber);
    if (!orderData) return false; // 데이터 로딩 중 → 재시도

    const { payment } = orderData;
    if (!payment || payment.pg_provider !== 'nhnkcp' || !payment.transaction_id) {
        return true; // nhnkcp 아님 → 더 시도 불필요
    }

    const container = findPaymentRowsContainer();
    if (!container) return false; // DOM 아직 미렌더링 → 재시도

    container.appendChild(buildReceiptRow(orderNumber));
    console.info(`[${PLUGIN_ID}] receipt button injected on mypage order show`);
    return true;
}

function startPolling(orderNumber: string): void {
    let attempts = 0;
    const id = setInterval(() => {
        attempts++;
        void tryInject(orderNumber).then(done => {
            if (done || attempts >= 20) clearInterval(id);
        });
    }, 400);
}

function onRouteChange(): void {
    const match = location.pathname.match(ORDER_SHOW_RE);
    if (match) startPolling(match[1]);
}

export function installMypageOrderShowInjector(): void {
    if (typeof window === 'undefined') return;
    const w = window as Record<string, unknown>;
    if (w[FLAG]) return;
    w[FLAG] = true;

    console.info(`[${PLUGIN_ID}] mypage order show injector installed`);

    const schedule = (delay = 800) => setTimeout(onRouteChange, delay);

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => schedule());
    } else {
        schedule();
    }

    // SPA 네비게이션 감지
    const origPush = history.pushState.bind(history);
    history.pushState = (...args: Parameters<typeof history.pushState>) => {
        origPush(...args);
        schedule(600);
    };
    window.addEventListener('popstate', () => schedule(500));
}
