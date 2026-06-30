import { afterEach, beforeEach, describe, expect, it } from 'vitest';
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
        .map((button) => (button.textContent ?? '').replace(/\s+/g, ' ').trim());
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
    });

    it('주문설정에서 렌더링된 NHN KCP 간편결제는 KCP 플러그인 설정과 무관하게 유지한다', async () => {
        await syncRenderedCheckoutEasyPayMethods();

        expect(visibleButtonTexts()).toEqual([
            'Naver Pay (KG Inicis) Pay with Naver Pay',
            'N NaverPay NHN KCP easy payment',
            'NP NaverPay Point NHN KCP point easy payment',
            'K KakaoPay NHN KCP easy payment',
            'A Apple Pay NHN KCP easy payment',
            'Credit Card Pay securely with credit card',
        ]);
        expect(document.querySelectorAll('[data-nhnkcp-easy-pay-hidden="true"]')).toHaveLength(0);
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
        }

        await syncRenderedCheckoutEasyPayMethods();

        expect(naverButton?.dataset.kginicisBrandPaymentMethod).toBeUndefined();
        expect(naverButton?.textContent).toContain('NaverPay');
        expect(naverButton?.textContent).toContain('NHN KCP easy payment');
        expect(naverButton?.textContent).not.toContain('KG Inicis');

        expect(kakaoButton?.dataset.kginicisBrandPaymentMethod).toBeUndefined();
        expect(kakaoButton?.textContent).toContain('KakaoPay');
        expect(kakaoButton?.textContent).toContain('NHN KCP easy payment');
        expect(kakaoButton?.textContent).not.toContain('KG Inicis');
    });

    it('Apple Pay도 주문설정에서 렌더링된 경우 숨기지 않는다', async () => {
        await syncRenderedCheckoutEasyPayMethods();

        const appleButton = document.querySelector<HTMLButtonElement>('[data-nhnkcp-easy-pay-method="nhnkcp_applepay"]');

        expect(appleButton).not.toBeNull();
        expect(appleButton?.hidden).toBe(false);
        expect(appleButton?.style.display).not.toBe('none');
        expect(appleButton?.textContent).toContain('NHN KCP easy payment');
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
        expect(buttons[2].textContent).toContain('NaverPay');
        expect(buttons[2].textContent).toContain('NHN KCP easy payment');
        expect(buttons[2].textContent).not.toContain('KG Inicis');
        expect(buttons[3].dataset.nhnkcpEasyPayMethod).toBe('nhnkcp_naverpay_point');
        expect(buttons[3].hidden).toBe(false);
        expect(buttons[3].textContent).toContain('NaverPay Point');
        expect(buttons[3].textContent).toContain('NHN KCP point easy payment');
        expect(buttons[3].textContent).not.toContain('KG Inicis');
        expect(buttons[4].dataset.nhnkcpEasyPayMethod).toBe('nhnkcp_kakaopay');
        expect(buttons[4].hidden).toBe(false);
        expect(buttons[4].textContent).toContain('KakaoPay');
        expect(buttons[4].textContent).toContain('NHN KCP easy payment');
        expect(buttons[4].textContent).not.toContain('KG Inicis');
    });
});
