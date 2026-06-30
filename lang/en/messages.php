<?php

declare(strict_types=1);

return [
    'refund' => [
        'missing_tid' => 'Cannot process refund: transaction number (tno) is missing.',
        'default_reason' => 'Buyer refund request',
    ],
    'errors' => [
        'wsdl_missing' => 'KCP WSDL file not found: :file',
        'approval_key_error' => 'KCP approval key error [:code]: :message',
        'soap_error' => 'KCP SOAP integration error: :message',
    ],
];
