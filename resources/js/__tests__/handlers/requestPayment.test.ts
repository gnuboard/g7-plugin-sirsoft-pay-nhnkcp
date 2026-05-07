/**
 * requestPayment 핸들러 테스트
 *
 * NHN KCP 결제창 호출 핸들러의 입력 검증 및 에러 경로 동작을 검증합니다.
 * iframe SDK 로드 / KCP_Pay_Execute 호출 등 외부 부수효과 의존 흐름은
 * tests/scenarios 매니페스트(통합 시나리오)에서 다루며, 본 단위 테스트는
 * "초기 가드 + catch 블록 정상 호출" 두 축에 집중합니다.
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { requestPaymentHandler } from '../../handlers/requestPayment';

const PG_PAYMENT = {
    order_number: 'ORD-001',
    order_name: 'Test Order',
    amount: 10000,
    pay_method: 'card',
};

describe('requestPaymentHandler', () => {
    let apiGet: ReturnType<typeof vi.fn>;
    let setLocalSpy: ReturnType<typeof vi.fn>;

    beforeEach(() => {
        apiGet = vi.fn();
        setLocalSpy = vi.fn();
        (window as Record<string, unknown>).G7Core = {
            api: { get: apiGet },
            state: { setLocal: setLocalSpy },
            toast: { error: vi.fn() },
        };
    });

    afterEach(() => {
        delete (window as Record<string, unknown>).G7Core;
        vi.restoreAllMocks();
    });

    it('pgPaymentData가 없으면 console.error 후 조기 반환', async () => {
        const consoleSpy = vi.spyOn(console, 'error').mockImplementation(() => {});

        await requestPaymentHandler({ params: {} });

        expect(consoleSpy).toHaveBeenCalledWith(
            expect.stringContaining('pgPaymentData is required')
        );
        expect(apiGet).not.toHaveBeenCalled();
        expect(setLocalSpy).not.toHaveBeenCalled();
    });

    it('client config 응답에 data 가 없으면 console.error 후 조기 반환', async () => {
        const consoleSpy = vi.spyOn(console, 'error').mockImplementation(() => {});
        apiGet.mockResolvedValue({}); // data 누락

        await requestPaymentHandler({ params: { pgPaymentData: PG_PAYMENT } });

        expect(consoleSpy).toHaveBeenCalledWith(
            expect.stringContaining('Failed to fetch client config'),
            expect.anything()
        );
        expect(setLocalSpy).not.toHaveBeenCalled();
    });

    it('client config API 자체가 throw 하면 catch 블록에서 setLocal 복구', async () => {
        vi.spyOn(console, 'error').mockImplementation(() => {});
        apiGet.mockRejectedValue(new Error('Network error'));

        await requestPaymentHandler({ params: { pgPaymentData: PG_PAYMENT } });

        // catch 블록은 paymentMethod를 pgPaymentData.pay_method 또는 'card' 로 setLocal 복구
        expect(setLocalSpy).toHaveBeenCalledWith(
            expect.objectContaining({
                isSubmittingOrder: false,
                paymentMethod: 'card',
            })
        );
    });

    it('pay_method 가 vbank 면 catch 시 그 값으로 복구', async () => {
        vi.spyOn(console, 'error').mockImplementation(() => {});
        apiGet.mockRejectedValue(new Error('boom'));

        await requestPaymentHandler({
            params: {
                pgPaymentData: { ...PG_PAYMENT, pay_method: 'vbank' },
            },
        });

        expect(setLocalSpy).toHaveBeenCalledWith(
            expect.objectContaining({
                isSubmittingOrder: false,
                paymentMethod: 'vbank',
            })
        );
    });
});
