import {
    isIosMobileDevice,
    isNhnKcpApplePayMethod,
} from './support/applePayDevice';

const PLUGIN_ID = 'sirsoft-pay_nhnkcp';
const FLAG = '__kcpCheckoutEasyPayInjectorInstalled';
const CHECKOUT_RE = /^\/shop\/checkout\/?$/;
const LISTENER_FLAG = '__kcpCheckoutEasyPaySyncListenerAttached';
const SYNC_RETRY_INTERVAL_MS = 200;
const SYNC_RETRY_ATTEMPTS = 120;

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
        labels: ['PAYCO (NHN KCP)', 'PAYCO NHN KCP 간편결제', 'PAYCO로 결제 (NHN KCP)', 'Pay with PAYCO (NHN KCP)'],
        ko: {
            heading: 'PAYCO',
            description: 'PAYCO로 결제 (NHN KCP)',
            title: 'PAYCO로 결제 (NHN KCP)',
        },
        en: {
            heading: 'PAYCO',
            description: 'Pay with PAYCO (NHN KCP)',
            title: 'Pay with PAYCO (NHN KCP)',
        },
        markText: 'P',
        markClassName: 'bg-red-500 text-white',
    },
    {
        id: 'nhnkcp_naverpay',
        configKey: 'NAVERPAY',
        labels: ['네이버페이 (NHN KCP)', '네이버페이 신용카드로 결제 (NHN KCP)', 'NaverPay NHN KCP 간편결제', 'Naver Pay (NHN KCP)', 'NaverPay (NHN KCP)', 'Pay by Naver Pay credit card (NHN KCP)'],
        ko: {
            heading: '네이버페이 (카드)',
            description: '네이버페이 신용카드로 결제 (NHN KCP)',
            title: '네이버페이 신용카드로 결제 (NHN KCP)',
        },
        en: {
            heading: 'Naver Pay (Card)',
            description: 'Pay by Naver Pay credit card (NHN KCP)',
            title: 'Pay by Naver Pay credit card (NHN KCP)',
        },
        markText: 'N',
        markClassName: 'bg-green-500 text-white',
    },
    {
        id: 'nhnkcp_naverpay_point',
        configKey: 'NAVERPAY POINT',
        labels: ['네이버페이 포인트 (NHN KCP)', '네이버페이 머니/포인트로 결제 (NHN KCP)', 'NaverPay 포인트 NHN KCP 포인트 간편결제', 'Naver Pay Point (NHN KCP)', 'NaverPay Point (NHN KCP)', 'Pay with Naver Pay Money/Points (NHN KCP)'],
        ko: {
            heading: '네이버페이 (포인트)',
            description: '네이버페이 머니/포인트로 결제 (NHN KCP)',
            title: '네이버페이 머니/포인트로 결제 (NHN KCP)',
        },
        en: {
            heading: 'Naver Pay (Point)',
            description: 'Pay with Naver Pay Money/Points (NHN KCP)',
            title: 'Pay with Naver Pay Money/Points (NHN KCP)',
        },
        markText: 'NP',
        markClassName: 'bg-green-600 text-white',
    },
    {
        id: 'nhnkcp_kakaopay',
        configKey: 'KAKAOPAY',
        labels: ['카카오페이 (NHN KCP)', '카카오페이로 결제 (NHN KCP)', 'KakaoPay NHN KCP 간편결제', 'Kakao Pay (NHN KCP)', 'KakaoPay (NHN KCP)', 'Pay with Kakao Pay (NHN KCP)'],
        ko: {
            heading: '카카오페이',
            description: '카카오페이로 결제 (NHN KCP)',
            title: '카카오페이로 결제 (NHN KCP)',
        },
        en: {
            heading: 'Kakao Pay',
            description: 'Pay with Kakao Pay (NHN KCP)',
            title: 'Pay with Kakao Pay (NHN KCP)',
        },
        markText: 'K',
        markClassName: 'bg-yellow-400 text-gray-950',
    },
    {
        id: 'nhnkcp_applepay',
        configKey: 'APPLEPAY',
        labels: ['애플페이 (NHN KCP)', '애플페이로 결제 (NHN KCP)', 'Apple Pay NHN KCP 간편결제', 'Apple Pay (NHN KCP)', 'Pay with Apple Pay (NHN KCP)'],
        ko: {
            heading: '애플페이',
            description: '애플페이로 결제 (NHN KCP)',
            title: '애플페이로 결제 (NHN KCP)',
        },
        en: {
            heading: 'Apple Pay',
            description: 'Pay with Apple Pay (NHN KCP)',
            title: 'Pay with Apple Pay (NHN KCP)',
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

function displayText(value: string): string {
    return value
        .replaceAll('네이버페이', '네이\u200B버페이')
        .replaceAll('카카오페이', '카카\u200B오페이')
        .replaceAll('Naver Pay', 'Naver\u200B Pay')
        .replaceAll('Kakao Pay', 'Kakao\u200B Pay');
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

function alignPaymentTextLeft(button: HTMLButtonElement, heading?: HTMLElement, description?: HTMLElement): void {
    button.style.textAlign = 'left';

    const row = findPaymentRow(button);
    if (row) {
        row.style.justifyContent = 'flex-start';
        row.style.textAlign = 'left';
        row.style.width = '100%';
    }

    const textContainer = heading?.parentElement ?? description?.parentElement;
    if (textContainer instanceof HTMLElement) {
        textContainer.style.textAlign = 'left';
        textContainer.style.minWidth = '0';
    }
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

    button.querySelectorAll<HTMLElement>('[data-kginicis-brand-payment-mark="true"], [data-kginicis-naverpay-mark="true"]').forEach((element) => {
        element.remove();
    });

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
    const headingText = displayText(copy.heading);
    const descriptionText = displayText(copy.description);

    const heading = paragraphs[0];
    const description = paragraphs[1];

    if (heading && heading.textContent !== headingText) {
        heading.textContent = headingText;
    }
    if (heading) {
        heading.setAttribute('aria-label', copy.heading);
        heading.style.textAlign = 'left';
        heading.style.whiteSpace = 'normal';
        heading.style.wordBreak = 'keep-all';
        heading.style.overflowWrap = 'anywhere';
    }

    if (description && description.textContent !== descriptionText) {
        description.textContent = descriptionText;
    }
    if (description) {
        description.style.textAlign = 'left';
        description.style.fontSize = '12px';
        description.style.lineHeight = '1rem';
        description.style.whiteSpace = 'normal';
        description.style.wordBreak = 'keep-all';
        description.style.overflowWrap = 'anywhere';
    }

    alignPaymentTextLeft(button, heading, description);
    button.title = copy.title;
}

function showButton(button: HTMLButtonElement, definition: EasyPayDefinition): void {
    if (isNhnKcpApplePayMethod(definition.id) && !isIosMobileDevice()) {
        button.hidden = true;
        button.disabled = true;
        button.style.display = 'none';
        button.setAttribute('aria-hidden', 'true');
        button.dataset.nhnkcpEasyPayMethod = definition.id;
        button.dataset.nhnkcpEasyPayHidden = 'true';
        delete button.dataset.nhnkcpEasyPayVisible;
        return;
    }

    if (button.hidden) button.hidden = false;
    if (button.disabled) button.disabled = false;
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

        if (attempts >= SYNC_RETRY_ATTEMPTS) {
            stopRetries();
        }
    }, SYNC_RETRY_INTERVAL_MS);

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
