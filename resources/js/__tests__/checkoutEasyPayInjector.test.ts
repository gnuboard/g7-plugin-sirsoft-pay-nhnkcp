import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    resetCheckoutEasyPayInjectorForTests,
    syncRenderedCheckoutEasyPayMethods,
} from '../checkoutEasyPayInjector';

function paymentButton(label: string, description: string): string {
    return `
        <button type="button">
            <div class="flex items-center gap-3">
                <i class="fas fa-wallet" role="img"></i>
                <div>
                    <p>${label}</p>
                    <p>${description}</p>
                </div>
            </div>
        </button>
    `;
}

function renderPaymentButtons(): void {
    document.body.innerHTML = `
        ${paymentButton('Naver Pay (KG Inicis)', 'Pay with Naver Pay')}
        ${paymentButton('Kakao Pay (KG Inicis)', 'Pay with Kakao Pay')}
        ${paymentButton('PAYCO (NHN KCP)', 'Pay with PAYCO')}
        ${paymentButton('Naver Pay (NHN KCP)', 'Pay with Naver Pay')}
        ${paymentButton('Naver Pay Point (NHN KCP)', 'Pay with Naver Pay points')}
        ${paymentButton('Kakao Pay (NHN KCP)', 'Pay with Kakao Pay')}
        ${paymentButton('Apple Pay (NHN KCP)', 'Pay with Apple Pay')}
        ${paymentButton('Credit Card', 'Pay securely with credit card')}
    `;
}

function visibleButtonTexts(): string[] {
    return Array.from(document.querySelectorAll<HTMLButtonElement>('button'))
        .filter((button) => !button.hidden && button.style.display !== 'none')
        .map((button) => plainText(button));
}

function plainText(element: Element | null | undefined): string {
    return (element?.textContent ?? '').replace(/\u200B/g, '').replace(/\s+/g, ' ').trim();
}

function mockIosDevice(): void {
    vi.spyOn(window.navigator, 'userAgent', 'get').mockReturnValue(
        'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1',
    );
    vi.spyOn(window.navigator, 'platform', 'get').mockReturnValue('iPhone');
}

describe('checkoutEasyPayInjector', () => {
    beforeEach(() => {
        document.documentElement.lang = 'en';
        window.history.pushState({}, '', '/shop/checkout');
        renderPaymentButtons();
    });

    afterEach(() => {
        resetCheckoutEasyPayInjectorForTests();
        document.body.innerHTML = '';
        vi.restoreAllMocks();
    });

    it('주문설정에서 렌더링된 NHN KCP 간편결제는 KCP 플러그인 설정과 무관하게 유지한다', async () => {
        mockIosDevice();

        await syncRenderedCheckoutEasyPayMethods();

        expect(visibleButtonTexts()).toEqual([
            'Naver Pay (KG Inicis) Pay with Naver Pay',
            'Kakao Pay (KG Inicis) Pay with Kakao Pay',
            'P PAYCO Pay with PAYCO (NHN KCP)',
            'N Naver Pay (Card) Pay by Naver Pay credit card (NHN KCP)',
            'NP Naver Pay (Point) Pay with Naver Pay Money/Points (NHN KCP)',
            'K Kakao Pay Pay with Kakao Pay (NHN KCP)',
            'A Apple Pay Pay with Apple Pay (NHN KCP)',
            'Credit Card Pay securely with credit card',
        ]);
        expect(document.querySelectorAll('[data-nhnkcp-easy-pay-hidden="true"]')).toHaveLength(0);
    });

    it('한국어 체크아웃에서 요청한 NHN KCP 간편결제 문구와 왼쪽 정렬을 적용한다', async () => {
        mockIosDevice();
        document.documentElement.lang = 'ko';
        document.body.innerHTML = `
            ${paymentButton('PAYCO (NHN KCP)', 'PAYCO로 결제')}
            ${paymentButton('네이버페이 (NHN KCP)', '네이버페이로 결제')}
            ${paymentButton('네이버페이 포인트 (NHN KCP)', '네이버페이 포인트로 결제')}
            ${paymentButton('카카오페이 (NHN KCP)', '카카오페이로 결제')}
            ${paymentButton('Apple Pay (NHN KCP)', 'Apple Pay로 결제')}
        `;

        await syncRenderedCheckoutEasyPayMethods();

        expect(visibleButtonTexts()).toEqual([
            'P PAYCO PAYCO로 결제 (NHN KCP)',
            'N 네이버페이 (카드) 네이버페이 신용카드로 결제 (NHN KCP)',
            'NP 네이버페이 (포인트) 네이버페이 머니/포인트로 결제 (NHN KCP)',
            'K 카카오페이 카카오페이로 결제 (NHN KCP)',
            'A 애플페이 애플페이로 결제 (NHN KCP)',
        ]);

        const paycoButton = document.querySelector<HTMLButtonElement>('[data-nhnkcp-easy-pay-method="nhnkcp_payco"]');
        const appleButton = document.querySelector<HTMLButtonElement>('[data-nhnkcp-easy-pay-method="nhnkcp_applepay"]');
        const paycoRow = paycoButton?.querySelector<HTMLElement>('.flex.items-center');
        const appleRow = appleButton?.querySelector<HTMLElement>('.flex.items-center');

        expect(paycoButton?.style.textAlign).toBe('left');
        expect(appleButton?.style.textAlign).toBe('left');
        expect(paycoRow?.style.justifyContent).toBe('flex-start');
        expect(appleRow?.style.justifyContent).toBe('flex-start');
        expect(paycoButton?.querySelectorAll('p')[0].style.textAlign).toBe('left');
        expect(appleButton?.querySelectorAll('p')[0].style.textAlign).toBe('left');
    });

    it('KG 이니시스 브랜드 보정기가 다시 잡지 않도록 충돌되는 원문 매칭을 피한다', async () => {
        await syncRenderedCheckoutEasyPayMethods();

        const naverButton = document.querySelector<HTMLButtonElement>('[data-nhnkcp-easy-pay-method="nhnkcp_naverpay"]');
        const kakaoButton = document.querySelector<HTMLButtonElement>('[data-nhnkcp-easy-pay-method="nhnkcp_kakaopay"]');

        expect(naverButton?.textContent).not.toContain('Naver Pay');
        expect(kakaoButton?.textContent).not.toContain('Kakao Pay');
        expect((naverButton?.textContent ?? '').replace(/\u200B/g, '')).toContain('Naver Pay (Card)');
        expect((kakaoButton?.textContent ?? '').replace(/\u200B/g, '')).toContain('Kakao Pay');
    });

    it('KG 브랜드 문구로 바뀐 버튼을 NHN KCP 문구로 복구한다', async () => {
        await syncRenderedCheckoutEasyPayMethods();

        const naverButton = document.querySelector<HTMLButtonElement>('[data-nhnkcp-easy-pay-method="nhnkcp_naverpay"]');
        const kakaoButton = document.querySelector<HTMLButtonElement>('[data-nhnkcp-easy-pay-method="nhnkcp_kakaopay"]');

        expect(naverButton).not.toBeNull();
        expect(kakaoButton).not.toBeNull();

        if (naverButton && kakaoButton) {
            naverButton.dataset.kginicisBrandPaymentMethod = 'kginicis_naverpay';
            naverButton.querySelectorAll('p')[0].textContent = 'Naver Pay';
            naverButton.querySelectorAll('p')[1].textContent = 'Pay with Naver Pay (KG Inicis)';

            kakaoButton.dataset.kginicisBrandPaymentMethod = 'kginicis_kakaopay';
            kakaoButton.querySelectorAll('p')[0].textContent = 'Kakao Pay';
            kakaoButton.querySelectorAll('p')[1].textContent = 'Pay with Kakao Pay (KG Inicis)';
            const kgMark = document.createElement('span');
            kgMark.dataset.kginicisBrandPaymentMark = 'true';
            kgMark.textContent = 'KakaoPay';
            kakaoButton.querySelector('.flex.items-center')?.prepend(kgMark);
        }

        await syncRenderedCheckoutEasyPayMethods();

        expect(naverButton?.dataset.kginicisBrandPaymentMethod).toBeUndefined();
        expect(plainText(naverButton)).toContain('Naver Pay (Card)');
        expect(plainText(naverButton)).toContain('Pay by Naver Pay credit card (NHN KCP)');
        expect(naverButton?.textContent).not.toContain('KG Inicis');

        expect(kakaoButton?.dataset.kginicisBrandPaymentMethod).toBeUndefined();
        expect(plainText(kakaoButton)).toContain('Kakao Pay');
        expect(plainText(kakaoButton)).toContain('Pay with Kakao Pay (NHN KCP)');
        expect(kakaoButton?.textContent).not.toContain('KG Inicis');
        expect(kakaoButton?.querySelector('[data-kginicis-brand-payment-mark="true"]')).toBeNull();
    });

    it('iOS 모바일 기기에서는 Apple Pay도 주문설정에서 렌더링된 경우 숨기지 않는다', async () => {
        mockIosDevice();

        await syncRenderedCheckoutEasyPayMethods();

        const appleButton = document.querySelector<HTMLButtonElement>('[data-nhnkcp-easy-pay-method="nhnkcp_applepay"]');

        expect(appleButton).not.toBeNull();
        expect(appleButton?.hidden).toBe(false);
        expect(appleButton?.style.display).not.toBe('none');
        expect(appleButton?.textContent).toContain('Pay with Apple Pay (NHN KCP)');
    });

    it('iOS 모바일 기기가 아니면 Apple Pay 버튼을 숨긴다', async () => {
        await syncRenderedCheckoutEasyPayMethods();

        const appleButton = document.querySelector<HTMLButtonElement>('[data-nhnkcp-easy-pay-method="nhnkcp_applepay"]');

        expect(appleButton).not.toBeNull();
        expect(appleButton?.hidden).toBe(true);
        expect(appleButton?.disabled).toBe(true);
        expect(appleButton?.style.display).toBe('none');
        expect(visibleButtonTexts()).not.toContain('A Apple Pay Pay with Apple Pay (NHN KCP)');
    });

    it('KG 보정기가 먼저 NHN KCP Naver/Kakao 버튼을 KG 버튼으로 바꿔도 중복 버튼을 회수한다', async () => {
        document.body.innerHTML = `
            ${paymentButton('NPay Naver Pay', 'Pay with Naver Pay (KG Inicis)')}
            ${paymentButton('KakaoPay Kakao Pay', 'Pay with Kakao Pay (KG Inicis)')}
            ${paymentButton('NPay Naver Pay', 'Pay with Naver Pay (KG Inicis)')}
            ${paymentButton('NPay Naver Pay', 'Pay with Naver Pay (KG Inicis)')}
            ${paymentButton('KakaoPay Kakao Pay', 'Pay with Kakao Pay (KG Inicis)')}
        `;

        const buttons = document.querySelectorAll<HTMLButtonElement>('button');
        buttons[0].dataset.kginicisBrandPaymentMethod = 'kginicis_naverpay';
        buttons[1].dataset.kginicisBrandPaymentMethod = 'kginicis_kakaopay';
        buttons[2].dataset.kginicisBrandPaymentMethod = 'kginicis_naverpay';
        buttons[3].dataset.kginicisBrandPaymentMethod = 'kginicis_naverpay';
        buttons[4].dataset.kginicisBrandPaymentMethod = 'kginicis_kakaopay';

        await syncRenderedCheckoutEasyPayMethods();

        expect(buttons[0].hidden).toBe(false);
        expect(buttons[1].hidden).toBe(false);
        expect(buttons[2].dataset.nhnkcpEasyPayMethod).toBe('nhnkcp_naverpay');
        expect(buttons[2].hidden).toBe(false);
        expect(plainText(buttons[2])).toContain('Naver Pay (Card)');
        expect(plainText(buttons[2])).toContain('Pay by Naver Pay credit card (NHN KCP)');
        expect(buttons[2].textContent).not.toContain('KG Inicis');
        expect(buttons[3].dataset.nhnkcpEasyPayMethod).toBe('nhnkcp_naverpay_point');
        expect(buttons[3].hidden).toBe(false);
        expect(plainText(buttons[3])).toContain('Naver Pay (Point)');
        expect(plainText(buttons[3])).toContain('Pay with Naver Pay Money/Points (NHN KCP)');
        expect(buttons[3].textContent).not.toContain('KG Inicis');
        expect(buttons[4].dataset.nhnkcpEasyPayMethod).toBe('nhnkcp_kakaopay');
        expect(buttons[4].hidden).toBe(false);
        expect(plainText(buttons[4])).toContain('Kakao Pay');
        expect(plainText(buttons[4])).toContain('Pay with Kakao Pay (NHN KCP)');
        expect(buttons[4].textContent).not.toContain('KG Inicis');
    });
});
