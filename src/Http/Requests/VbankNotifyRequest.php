<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNhnkcp\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * KCP 가상계좌 입금 통보 요청 검증
 *
 * POST /plugins/sirsoft-pay_nhnkcp/payment/vbank-notify
 *
 * KCP 서버가 직접 호출하는 입금 확인 웹훅입니다.
 */
class VbankNotifyRequest extends FormRequest
{
    /**
     * FormRequest 인가 — 외부 PG 웹훅이라 항상 true
     *
     * @return bool 항상 true
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * KCP 입금통보 페이로드 검증 규칙
     *
     * @return array<string, mixed> Laravel 검증 규칙
     */
    public function rules(): array
    {
        return [
            'tno' => ['required', 'string'],
            'ordr_idxx' => ['required', 'string'],
            'good_mny' => ['required', 'numeric', 'min:1'],
            'res_cd' => ['required', 'string'],
            'res_msg' => ['nullable', 'string'],
            'bank_name' => ['nullable', 'string'],
            'account' => ['nullable', 'string'],
            'account_holder' => ['nullable', 'string'],
            'vnbank_expire_date' => ['nullable', 'string'],
        ];
    }
}
