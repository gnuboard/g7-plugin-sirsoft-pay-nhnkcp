<?php

declare(strict_types=1);

return [
    'refund' => [
        'missing_tid' => '거래번호(tno)가 없어 환불을 진행할 수 없습니다.',
        'default_reason' => '구매자 환불 요청',
    ],
    'errors' => [
        'wsdl_missing' => 'KCP WSDL 파일이 없습니다: :file',
        'approval_key_error' => 'KCP 승인키 오류 [:code]: :message',
        'soap_error' => 'KCP SOAP 연동 오류: :message',
    ],
];
