<?php

// Ver README raiz, seção 3.4 (Contrato) para a justificativa de cada header.
return [
    'paths' => ['api/*'],
    'allowed_methods' => ['*'],
    'allowed_origins' => [env('WEB_ORIGIN', 'http://localhost:5173')],
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['Idempotency-Key', 'If-None-Match', 'Authorization', 'Content-Type'],
    'exposed_headers' => ['Idempotent-Replay', 'ETag', 'Retry-After', 'X-Request-Id'],
    'max_age' => 86400,
    'supports_credentials' => false,
];
