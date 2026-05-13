<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNhnkcp\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * KCP 에스크로 공통 통보 요청 검증
 *
 * KCP가 구매확인(TX02) 또는 배송시작(TX03) 이벤트 발생 시
 * 등록된 공통통보 URL로 POST 요청을 보냅니다.
 */
class EscrowCommonNotifyRequest extends FormRequest
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
     * KCP 에스크로 통보 페이로드 검증 규칙
     *
     * @return array<string, mixed> Laravel 검증 규칙
     */
    public function rules(): array
    {
        return [
            'tx_cd'     => ['required', 'string'],
            'tno'       => ['nullable', 'string'],
            'ordr_idxx' => ['required', 'string'],
            'cl_status' => ['nullable', 'string'],
            'good_mny'  => ['nullable', 'numeric'],
        ];
    }
}
