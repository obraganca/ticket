<?php

return [
    'reservation_ttl_minutes' => (int) env('RESERVATION_TTL_MINUTES', 15),
    'max_quantity_per_order' => (int) env('MAX_QUANTITY_PER_ORDER', 6),
    'outbox_max_attempts' => (int) env('OUTBOX_MAX_ATTEMPTS', 8),
    'outbox_sweep_grace_seconds' => (int) env('OUTBOX_SWEEP_GRACE_SECONDS', 60),
    'dev_tools_enabled' => filter_var(env('ENABLE_DEV_TOOLS', false), FILTER_VALIDATE_BOOL),
    'orders_rate_limit_per_minute' => (int) env('ORDERS_RATE_LIMIT_PER_MINUTE', 60),
    'login_rate_limit_per_minute' => (int) env('LOGIN_RATE_LIMIT_PER_MINUTE', 10),
];
