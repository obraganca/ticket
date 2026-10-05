<?php

namespace App\Infrastructure;

use App\Contracts\FinanceClient;
use App\Contracts\FinanceUnavailableException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Chama o finance-sim (projeto separado) com `Idempotency-Key` da operação: o
 * financeiro deduplica por ela, inclusive quando a chamada anterior "falhou" do
 * nosso lado mas já tinha sido gravada do lado dele (item 5.2: lento e instável).
 */
class HttpFinanceClient implements FinanceClient
{
    public function register(string $idempotencyKey, array $payload): void
    {
        try {
            $response = Http::timeout((int) config('services.finance.timeout', 10))
                ->withHeaders(['Idempotency-Key' => $idempotencyKey])
                ->post(rtrim(config('services.finance.url'), '/').'/sales', $payload);
        } catch (ConnectionException $e) {
            throw new FinanceUnavailableException('financeiro inacessível: '.$e->getMessage(), 0, $e);
        }

        if ($response->failed()) {
            throw new FinanceUnavailableException("finance-sim respondeu {$response->status()}");
        }
    }
}
