const PLUGIN_ID = 'sirsoft-pay_nhnkcp';
const FLAG = '__kcpCheckoutEasyPayInjectorInstalled';
const CONTAINER_ID = 'nhnkcp-checkout-easy-pay';
const LISTENER_FLAG = '__kcpClearListenerAttached';
const CHECKOUT_RE = /^\/shop\/checkout\/?$/;

const EASY_PAYS = [
    { key: 'PAYCO',          method: 'nhnkcp_payco',          label: 'PAYCO',           cls: 'bg-red-500 text-white' },
    { key: 'NAVERPAY',       method: 'nhnkcp_naverpay',       label: '네이버페이',        cls: 'bg-green-500 text-white' },
    { key: 'NAVERPAY POINT', method: 'nhnkcp_naverpay_point', label: '네이버페이 포인트', cls: 'bg-green-600 text-white' },
    { key: 'KAKAOPAY',       method: 'nhnkcp_kakaopay',       label: '카카오페이',        cls: 'bg-yellow-400 text-gray-900' },
    { key: 'APPLEPAY',       method: 'nhnkcp_applepay',       label: 'Apple Pay',        cls: 'bg-gray-900 dark:bg-gray-950 text-white' },
] as const;

let cachedEnabledPays: string[] | null = null;

async function fetchEnabledPays(): Promise<string[]> {
    if (cachedEnabledPays !== null) return cachedEnabledPays;
    try {
        const token = localStorage.getItem('auth_token');
        const res = await fetch('/api/modules/sirsoft-ecommerce/payments/client-config/nhnkcp', {
            headers: { Authorization: token ? `Bearer ${token}` : '', Accept: 'application/json' },
        });
        if (!res.ok) {
            cachedEnabledPays = [];
            return [];
        }
        const json = (await res.json()) as { data?: { enabled_easy_pays?: string[] } };
        cachedEnabledPays = json.data?.enabled_easy_pays ?? [];
    } catch {
        cachedEnabledPays = [];
    }
    return cachedEnabledPays;
}

function findPaymentContainer(): Element | null {
    const h2 = Array.from(document.querySelectorAll<HTMLElement>('h2')).find(
        el => el.textContent?.includes('결제'),
    );
    if (!h2) return null;

    let el: Element | null = h2.parentElement;
    while (el && el !== document.body) {
        if (el.tagName === 'DIV' && el.className?.includes('rounded-lg') && el.className?.includes('border')) {
            return el;
        }
        el = el.parentElement;
    }
    return null;
}

function updateButtonStyles(selectedMethod: string | null): void {
    const container = document.getElementById(CONTAINER_ID);
    if (!container) return;
    container.querySelectorAll<HTMLButtonElement>('button[data-kcp-method]').forEach(btn => {
        btn.style.boxShadow =
            btn.dataset.kcpMethod === selectedMethod
                ? '0 0 0 2px #ffffff, 0 0 0 5px rgba(0,0,0,0.55)'
                : '';
    });
}

function setEasyPayMethod(method: string): void {
    const g7 = (window as unknown as Record<string, unknown>).G7Core as Record<string, unknown> | undefined;
    (g7?.state as Record<string, unknown> | undefined)?.setLocal?.({
        paymentMethod: method,
        serverPaymentMethod: 'card',
    });
    updateButtonStyles(method);
}

function attachClearListener(payContainer: Element): void {
    const el = payContainer as Element & Record<string, unknown>;
    if (el[LISTENER_FLAG]) return;
    el[LISTENER_FLAG] = true;
    payContainer.addEventListener('click', e => {
        const target = e.target as Element;
        const easySection = document.getElementById(CONTAINER_ID);
        if (easySection && !easySection.contains(target)) {
            updateButtonStyles(null);
        }
    });
}

/**
 * GNU5 orderform.sub.php 동일 패턴:
 * Apple Pay는 iPhone/iPad/iPod (iOS Safari) 에서만 동작하므로
 * UA에 Apple 기기 식별자가 있을 때만 버튼을 노출한다.
 */
function isApplePayDevice(): boolean {
    if (typeof navigator === 'undefined') return false;
    return /iPhone|iPad|iPod/i.test(navigator.userAgent);
}

function buildEasyPaySection(enabledPays: string[]): HTMLElement | null {
    const btns: HTMLElement[] = [];

    for (const { key, method, label, cls } of EASY_PAYS) {
        if (!enabledPays.includes(key)) continue;
        if (key === 'APPLEPAY' && !isApplePayDevice()) continue;
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.dataset.kcpMethod = method;
        btn.className = `px-4 py-2 rounded-lg text-sm font-bold ${cls} hover:opacity-90`;
        btn.textContent = label;
        btn.addEventListener('click', () => setEasyPayMethod(method));
        btns.push(btn);
    }

    if (btns.length === 0) return null;

    const wrap = document.createElement('div');
    wrap.id = CONTAINER_ID;
    wrap.className = 'mt-4 pt-4 mb-4 border-t border-gray-200 dark:border-gray-700';

    const title = document.createElement('p');
    title.className = 'text-sm font-medium text-gray-700 dark:text-gray-300 mb-3';
    title.textContent = 'KCP 간편결제';

    const btnWrap = document.createElement('div');
    btnWrap.className = 'nhnkcp-easy-pay-btns flex flex-wrap gap-2';
    btns.forEach(b => btnWrap.appendChild(b));

    wrap.appendChild(title);
    wrap.appendChild(btnWrap);
    return wrap;
}

let isInjecting = false;
let pollingId: ReturnType<typeof setInterval> | null = null;

async function tryInject(): Promise<boolean> {
    if (document.getElementById(CONTAINER_ID)) return true;
    if (isInjecting) return false; // 비동기 주입 중 → 재시도

    const payContainer = findPaymentContainer();
    if (!payContainer) return false;

    isInjecting = true;
    try {
        const enabledPays = await fetchEnabledPays();

        // async 대기 중 다른 폴이 먼저 주입했을 수 있으므로 재확인
        if (document.getElementById(CONTAINER_ID)) return true;
        if (enabledPays.length === 0) return true;

        const section = buildEasyPaySection(enabledPays);
        if (!section) return true;

        payContainer.appendChild(section);
        attachClearListener(payContainer);
        console.info(`[${PLUGIN_ID}] checkout easy pay injected`);
        return false;
    } finally {
        isInjecting = false;
    }
}

function startPolling(): void {
    // 기존 interval 제거 후 재시작 (중복 방지)
    if (pollingId !== null) {
        clearInterval(pollingId);
        pollingId = null;
    }
    cachedEnabledPays = null;
    void fetchEnabledPays(); // API 선제 호출

    let attempts = 0;
    pollingId = setInterval(() => {
        attempts++;
        void tryInject().then(done => {
            if (done || attempts >= 50) {
                if (pollingId !== null) clearInterval(pollingId);
                pollingId = null;
            }
        });
    }, 200);
}

function onRouteChange(): void {
    if (CHECKOUT_RE.test(location.pathname)) startPolling();
}

export function installCheckoutEasyPayInjector(): void {
    if (typeof window === 'undefined') return;
    const w = window as unknown as Record<string, unknown>;
    if (w[FLAG]) return;
    w[FLAG] = true;

    console.info(`[${PLUGIN_ID}] checkout easy pay injector installed`);

    // 현재 페이지가 이미 체크아웃이면 즉시 시작
    if (CHECKOUT_RE.test(location.pathname)) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', () => startPolling());
        } else {
            startPolling();
        }
    }

    // SPA 이동 감지
    const origPush = history.pushState.bind(history);
    history.pushState = (...args: Parameters<typeof history.pushState>) => {
        origPush(...args);
        setTimeout(onRouteChange, 200); // 600ms → 200ms
    };
    window.addEventListener('popstate', () => setTimeout(onRouteChange, 200));
}
