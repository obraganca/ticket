<?php

return [
    'gateway' => [
        'secret' => env('GATEWAY_WEBHOOK_SECRET'),
        // Para onde os comandos gateway:* (simulador) enviam os avisos. Por padrão o
        // próprio servidor HTTP do container/host (não depende de APP_URL, que é a URL pública).
        'webhook_url' => env('GATEWAY_WEBHOOK_URL', 'http://127.0.0.1:8000/api/v1/webhooks/payments'),
    ],
    'finance' => [
        'url' => env('FINANCE_SIM_URL', 'http://finance-sim:8080'),
        // Deve ser MENOR que ProcessOutboxJob::$timeout.
        'timeout' => (int) env('FINANCE_HTTP_TIMEOUT', 10),
    ],
];
