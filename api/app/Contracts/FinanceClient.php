<?php

namespace App\Contracts;

interface FinanceClient
{
    /**
     * Registra uma operação (venda ou estorno) no financeiro. `$idempotencyKey` é a
     * chave DA OPERAÇÃO ("{order_id}:sale" / "{order_id}:refund").
     *
     * @throws FinanceUnavailableException
     */
    public function register(string $idempotencyKey, array $payload): void;
}
