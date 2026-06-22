import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { requestPaymentHandler } from '../handlers/requestPayment';
import { installOrderResponseInterceptor } from '../orderResponseInterceptor';

vi.mock('../handlers/requestPayment', () => ({
    requestPaymentHandler: vi.fn().mockResolvedValue(undefined),
}));

interface MutatedOrderResponse {
    data?: {
        redirect_url?: string;
        requires_pg_payment?: boolean;
    };
}

function windowRecord(): Record<string, unknown> {
    return window as unknown as Record<string, unknown>;
}

describe('installOrderResponseInterceptor', () => {
    beforeEach(() => {
        window.history.pushState({}, '', '/shop/checkout');
        vi.mocked(requestPaymentHandler).mockClear();
        vi.spyOn(console, 'info').mockImplementation(() => {});
    });

    afterEach(() => {
        const w = windowRecord();
        delete w['__sirsoftNhnkcpInterceptorInstalled'];
        delete w['__sirsoftPgOriginalFetch'];
        delete w['G7Core'];
        document.body.innerHTML = '';
        vi.restoreAllMocks();
    });

    it('NHN KCP 간편결제는 주문 생성 요청을 card로 전송하고 원래 결제수단으로 KCP 결제창을 호출한다', async () => {
        let sentBody = '';
        window.fetch = vi.fn().mockImplementation(async (_input, init?: RequestInit) => {
            sentBody = String(init?.body ?? '');

            return new Response(JSON.stringify({
                success: true,
                data: {
                    order: {
                        order_number: 'ORD-100',
                        total_amount: 15000,
                        orderer_name: 'Tester',
                        orderer_email: 'tester@example.test',
                        orderer_phone: '010-1234-5678',
                        options: [{ product_name: '테스트 상품' }],
                    },
                    redirect_url: '/shop/orders/ORD-100/complete',
                    requires_pg_payment: true,
                    pg_provider: 'sirsoft-kginicis',
                },
            }), {
                status: 200,
                headers: { 'Content-Type': 'application/json' },
            });
        }) as unknown as typeof fetch;

        installOrderResponseInterceptor();

        const response = await window.fetch('/api/modules/sirsoft-ecommerce/user/orders', {
            method: 'POST',
            body: JSON.stringify({ payment_method: 'nhnkcp_naverpay' }),
        });
        const body = (await response.json()) as MutatedOrderResponse;

        expect(JSON.parse(sentBody)).toMatchObject({ payment_method: 'card' });
        expect(requestPaymentHandler).toHaveBeenCalledWith({
            params: {
                pgPaymentData: expect.objectContaining({
                    order_number: 'ORD-100',
                    order_name: '테스트 상품',
                    amount: 15000,
                    customer_phone: '01012345678',
                }),
                paymentMethod: 'nhnkcp_naverpay',
            },
        });
        expect(body.data?.requires_pg_payment).toBe(false);
        expect(body.data?.redirect_url).toBe('/shop/checkout');
    });

    it('일반 NHN KCP 주문 응답도 결제창을 호출하고 fallback 이동을 막는다', async () => {
        window.fetch = vi.fn().mockResolvedValue(
            new Response(JSON.stringify({
                success: true,
                data: {
                    order: { order_number: 'ORD-101' },
                    redirect_url: '/shop/orders/ORD-101/complete',
                    requires_pg_payment: true,
                    pg_provider: 'sirsoft-nhnkcp',
                    pg_payment_data: {
                        order_number: 'ORD-101',
                        order_name: '일반 주문',
                        amount: 20000,
                    },
                },
            }), {
                status: 200,
                headers: { 'Content-Type': 'application/json' },
            }),
        ) as unknown as typeof fetch;

        installOrderResponseInterceptor();

        const response = await window.fetch('/api/modules/sirsoft-ecommerce/user/orders', {
            method: 'POST',
            body: JSON.stringify({ payment_method: 'card' }),
        });
        const body = (await response.json()) as MutatedOrderResponse;

        expect(requestPaymentHandler).toHaveBeenCalledWith({
            params: {
                pgPaymentData: expect.objectContaining({
                    order_number: 'ORD-101',
                    pay_method: 'card',
                }),
                paymentMethod: undefined,
            },
        });
        expect(body.data?.requires_pg_payment).toBe(false);
        expect(body.data?.redirect_url).toBe('/shop/checkout');
    });
});
