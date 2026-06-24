const PLUGIN_ID = 'sirsoft-pay_nhnkcp';
const FLAG = '__kcpCheckoutEasyPayInjectorInstalled';
const CHECKOUT_RE = /^\/shop\/checkout\/?$/;
const LISTENER_FLAG = '__kcpCheckoutEasyPaySyncListenerAttached';

interface EasyPayCopy {
    heading: string;
    description: string;
    title: string;
}

interface EasyPayDefinition {
    id: string;
    configKey: string;
    labels: string[];
    ko: EasyPayCopy;
    en: EasyPayCopy;
    markText: string;
    markClassName: string;
}

const EASY_PAY_DEFINITIONS: EasyPayDefinition[] = [
    {
        id: 'nhnkcp_payco',
        configKey: 'PAYCO',
        labels: ['PAYCO (NHN KCP)'],
        ko: {
            heading: 'PAYCO',
            description: 'NHN KCP 간편결제',
            title: 'PAYCO (NHN KCP)',
        },
        en: {
            heading: 'PAYCO',
            description: 'NHN KCP easy payment',
            title: 'PAYCO (NHN KCP)',
        },
        markText: 'P',
        markClassName: 'bg-red-500 text-white',
    },
    {
        id: 'nhnkcp_naverpay',
        configKey: 'NAVERPAY',
        labels: ['네이버페이 (NHN KCP)', 'Naver Pay (NHN KCP)', 'NaverPay (NHN KCP)'],
        ko: {
            heading: 'NaverPay',
            description: 'NHN KCP 간편결제',
            title: 'NaverPay (NHN KCP)',
        },
        en: {
            heading: 'NaverPay',
            description: 'NHN KCP easy payment',
            title: 'NaverPay (NHN KCP)',
        },
        markText: 'N',
        markClassName: 'bg-green-500 text-white',
    },
    {
        id: 'nhnkcp_naverpay_point',
        configKey: 'NAVERPAY POINT',
        labels: ['네이버페이 포인트 (NHN KCP)', 'Naver Pay Point (NHN KCP)', 'NaverPay Point (NHN KCP)'],
        ko: {
            heading: 'NaverPay 포인트',
            description: 'NHN KCP 포인트 간편결제',
            title: 'NaverPay 포인트 (NHN KCP)',
        },
        en: {
            heading: 'NaverPay Point',
            description: 'NHN KCP point easy payment',
            title: 'NaverPay Point (NHN KCP)',
        },
        markText: 'NP',
        markClassName: 'bg-green-600 text-white',
    },
    {
        id: 'nhnkcp_kakaopay',
        configKey: 'KAKAOPAY',
        labels: ['카카오페이 (NHN KCP)', 'Kakao Pay (NHN KCP)', 'KakaoPay (NHN KCP)'],
        ko: {
            heading: 'KakaoPay',
            description: 'NHN KCP 간편결제',
            title: 'KakaoPay (NHN KCP)',
        },
        en: {
            heading: 'KakaoPay',
            description: 'NHN KCP easy payment',
            title: 'KakaoPay (NHN KCP)',
        },
        markText: 'K',
        markClassName: 'bg-yellow-400 text-gray-950',
    },
    {
        id: 'nhnkcp_applepay',
        configKey: 'APPLEPAY',
        labels: ['Apple Pay (NHN KCP)'],
        ko: {
            heading: 'Apple Pay',
            description: 'NHN KCP 간편결제',
            title: 'Apple Pay (NHN KCP)',
        },
        en: {
            heading: 'Apple Pay',
            description: 'NHN KCP easy payment',
            title: 'Apple Pay (NHN KCP)',
        },
        markText: 'A',
        markClassName: 'bg-gray-900 text-white',
    },
];

let observer: MutationObserver | null = null;
let retryTimer: number | null = null;
let syncQueued = false;

function windowRecord(): Record<string, unknown> {
    return window as unknown as Record<string, unknown>;
}

function isCheckoutPage(): boolean {
    return CHECKOUT_RE.test(window.location.pathname);
}

function normalizedText(value: string | null | undefined): string {
    return (value ?? '').replace(/\s+/g, ' ').trim();
}

function isKoreanPage(): boolean {
    const lang = document.documentElement.lang || navigator.language || '';

    return lang.toLowerCase().startsWith('ko');
}

function copyFor(definition: EasyPayDefinition): EasyPayCopy {
    return isKoreanPage() ? definition.ko : definition.en;
}

function findDefinitionById(id: string | undefined): EasyPayDefinition | null {
    return EASY_PAY_DEFINITIONS.find((definition) => definition.id === id) ?? null;
}

function findDefinitionForButton(button: HTMLButtonElement): EasyPayDefinition | null {
    const markedDefinition = findDefinitionById(button.dataset.nhnkcpEasyPayMethod);
    if (markedDefinition) return markedDefinition;

    const text = normalizedText(button.textContent);

    return EASY_PAY_DEFINITIONS.find((definition) => (
        definition.labels.some((label) => text.includes(label))
    )) ?? null;
}

function findPaymentRow(button: HTMLButtonElement): HTMLElement | null {
    return button.querySelector<HTMLElement>('.flex.items-center.gap-2, .flex.items-center.gap-3')
        ?? button.querySelector<HTMLElement>('.flex.items-center');
}

function createMark(definition: EasyPayDefinition): HTMLSpanElement {
    const mark = document.createElement('span');
    mark.dataset.nhnkcpEasyPayMark = 'true';
    mark.dataset.nhnkcpEasyPayMethod = definition.id;
    mark.setAttribute('aria-hidden', 'true');
    mark.className = `inline-flex items-center justify-center rounded-lg text-xs font-bold ${definition.markClassName}`;
    mark.style.width = '32px';
    mark.style.height = '32px';
    mark.style.flex = '0 0 32px';
    mark.textContent = definition.markText;

    return mark;
}

function removeKginicisBrandArtifacts(button: HTMLButtonElement): void {
    delete button.dataset.kginicisBrandPaymentButton;
    delete button.dataset.kginicisBrandPaymentMethod;
    delete button.dataset.kginicisNaverpayBrandButton;

    button.querySelectorAll<HTMLElement>('[data-kginicis-brand-payment-heading], [data-kginicis-brand-payment-description], [data-kginicis-naverpay-heading], [data-kginicis-naverpay-description]').forEach((element) => {
        delete element.dataset.kginicisBrandPaymentHeading;
        delete element.dataset.kginicisBrandPaymentDescription;
        delete element.dataset.kginicisNaverpayHeading;
        delete element.dataset.kginicisNaverpayDescription;
    });
}

function findPaymentIcon(button: HTMLButtonElement): Element | null {
    return button.querySelector('[data-kginicis-brand-payment-mark="true"], [data-kginicis-naverpay-mark="true"]')
        ?? button.querySelector('[data-nhnkcp-easy-pay-mark="true"]')
        ?? button.querySelector('svg')
        ?? button.querySelector('i[class*="fa-"], i[role="img"], i');
}

function ensureMark(button: HTMLButtonElement, definition: EasyPayDefinition): void {
    const existingMark = button.querySelector<HTMLElement>('[data-nhnkcp-easy-pay-mark="true"]');
    if (existingMark) {
        existingMark.dataset.nhnkcpEasyPayMethod = definition.id;
        existingMark.className = `inline-flex items-center justify-center rounded-lg text-xs font-bold ${definition.markClassName}`;
        existingMark.textContent = definition.markText;
        return;
    }

    const mark = createMark(definition);
    const icon = findPaymentIcon(button);
    if (icon && icon.parentElement) {
        icon.replaceWith(mark);
        return;
    }

    const row = findPaymentRow(button);
    row?.prepend(mark);
}

function updatePaymentText(button: HTMLButtonElement, definition: EasyPayDefinition): void {
    const paragraphs = Array.from(button.querySelectorAll<HTMLParagraphElement>('p'));
    const copy = copyFor(definition);

    const heading = paragraphs[0];
    const description = paragraphs[1];

    if (heading && heading.textContent !== copy.heading) {
        heading.textContent = copy.heading;
    }
    if (heading) {
        heading.setAttribute('aria-label', copy.heading);
        heading.style.whiteSpace = 'normal';
        heading.style.wordBreak = 'keep-all';
        heading.style.overflowWrap = 'anywhere';
    }

    if (description && description.textContent !== copy.description) {
        description.textContent = copy.description;
    }
    if (description) {
        description.style.fontSize = '12px';
        description.style.lineHeight = '1rem';
        description.style.whiteSpace = 'normal';
        description.style.wordBreak = 'keep-all';
        description.style.overflowWrap = 'anywhere';
    }

    button.title = copy.title;
}

function showButton(button: HTMLButtonElement, definition: EasyPayDefinition): void {
    if (button.hidden) button.hidden = false;
    if (button.style.display === 'none') button.style.removeProperty('display');
    button.removeAttribute('aria-hidden');
    button.dataset.nhnkcpEasyPayMethod = definition.id;
    button.dataset.nhnkcpEasyPayVisible = 'true';
    delete button.dataset.nhnkcpEasyPayHidden;

    removeKginicisBrandArtifacts(button);
    ensureMark(button, definition);
    updatePaymentText(button, definition);
}

function reconcileKginicisPatchedDuplicates(root: ParentNode): boolean {
    const duplicateGroups: Record<string, string[]> = {
        kginicis_naverpay: ['nhnkcp_naverpay', 'nhnkcp_naverpay_point'],
        kginicis_kakaopay: ['nhnkcp_kakaopay'],
    };
    let changed = false;

    Object.entries(duplicateGroups).forEach(([kginicisMethod, nhnkcpMethods]) => {
        const buttons = Array.from(root.querySelectorAll<HTMLButtonElement>(`button[data-kginicis-brand-payment-method="${kginicisMethod}"]`))
            .filter((button) => !button.dataset.nhnkcpEasyPayMethod);

        buttons.slice(1).forEach((button, index) => {
            const definition = findDefinitionById(nhnkcpMethods[index]);
            if (!definition) return;

            showButton(button, definition);
            changed = true;
        });
    });

    return changed;
}

export async function syncRenderedCheckoutEasyPayMethods(
    root: ParentNode = document,
): Promise<boolean> {
    if (typeof window === 'undefined' || typeof document === 'undefined') return false;
    if (!isCheckoutPage()) return false;

    let changed = false;

    root.querySelectorAll<HTMLButtonElement>('button').forEach((button) => {
        const definition = findDefinitionForButton(button);
        if (!definition) return;

        showButton(button, definition);
        changed = true;
    });

    if (reconcileKginicisPatchedDuplicates(root)) {
        changed = true;
    }

    return changed;
}

function queueSync(): void {
    if (syncQueued) return;
    syncQueued = true;

    window.setTimeout(() => {
        syncQueued = false;
        void syncRenderedCheckoutEasyPayMethods();
    }, 0);
}

function stopRetries(): void {
    if (retryTimer === null) return;

    window.clearInterval(retryTimer);
    retryTimer = null;
}

function startSync(): void {
    if (!isCheckoutPage()) return;

    stopRetries();
    void syncRenderedCheckoutEasyPayMethods();

    let attempts = 0;
    retryTimer = window.setInterval(() => {
        attempts += 1;
        void syncRenderedCheckoutEasyPayMethods();

        if (attempts >= 50) {
            stopRetries();
        }
    }, 200);

    const body = document.body as HTMLElement & Record<string, unknown>;
    if (body[LISTENER_FLAG]) return;
    body[LISTENER_FLAG] = true;

    observer = new MutationObserver(() => queueSync());
    observer.observe(document.body, { childList: true, subtree: true, characterData: true });
}

function onRouteChange(): void {
    if (isCheckoutPage()) startSync();
}

export function installCheckoutEasyPayInjector(): void {
    if (typeof window === 'undefined' || typeof document === 'undefined') return;
    const w = windowRecord();
    if (w[FLAG]) return;
    w[FLAG] = true;

    console.info(`[${PLUGIN_ID}] checkout easy pay method sync installed`);

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => startSync());
    } else {
        startSync();
    }

    const origPush = history.pushState.bind(history);
    history.pushState = (...args: Parameters<typeof history.pushState>) => {
        origPush(...args);
        window.setTimeout(() => onRouteChange(), 200);
    };
    window.addEventListener('popstate', () => window.setTimeout(() => onRouteChange(), 200));
}

export function resetCheckoutEasyPayInjectorForTests(): void {
    observer?.disconnect();
    observer = null;
    stopRetries();
    syncQueued = false;
    delete windowRecord()[FLAG];
    if (document.body) {
        delete ((document.body as HTMLElement & Record<string, unknown>)[LISTENER_FLAG]);
    }
}
