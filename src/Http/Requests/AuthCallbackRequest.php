<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\Pay\Nhnkcp\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Log;

/**
 * KCP 결제 승인 콜백 요청 검증
 *
 * POST /plugins/sirsoft-pay-nhnkcp/payment/callback
 *
 * KCP가 브라우저를 통해 POST 방식으로 전달하는 파라미터입니다.
 */
class AuthCallbackRequest extends FormRequest
{
    private const PLUGIN_IDENTIFIER = 'sirsoft-pay-nhnkcp';

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'res_cd' => ['required', 'string'],
            'res_msg' => ['nullable', 'string'],
            'enc_data' => ['required', 'string'],
            'enc_info' => ['required', 'string'],
            'tno' => ['nullable', 'string'],
            'ordr_idxx' => ['required', 'string'],
            'good_mny' => ['nullable', 'numeric', 'min:1'],
            'use_pay_method' => ['nullable', 'string'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        Log::warning('KCP: authCallback validation failed', [
            'errors' => $validator->errors()->toArray(),
            'input' => array_keys($this->all()),
        ]);

        $settings = plugin_settings(self::PLUGIN_IDENTIFIER);
        $baseUrl = $settings['redirect_fail_url'] ?? '/shop/checkout';
        $separator = str_contains($baseUrl, '?') ? '&' : '?';

        throw new HttpResponseException(
            redirect($baseUrl . $separator . http_build_query(['error' => 'invalid_params']))
        );
    }
}
