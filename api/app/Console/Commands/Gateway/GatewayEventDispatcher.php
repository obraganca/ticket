<?php

namespace App\Console\Commands\Gateway;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Simula o gateway de pagamento externo: assina os avisos (HMAC-SHA256 do corpo
 * bruto, mesmo segredo do middleware VerifyGatewaySignature) e chama o webhook por
 * HTTP real, como o provedor faria. Cobre os três comportamentos do item 4.2:
 *  (a) aviso repetido       -> sendDuplicated
 *  (b) fora de ordem        -> sendOutOfOrder
 *  (c) reenvio por lentidão -> sendRetryOnTimeout
 */
class GatewayEventDispatcher
{
    public function send(string $orderId, string $type, ?string $eventId = null, ?string $occurredAt = null): array
    {
        $payload = $this->payload($orderId, $type, $eventId, $occurredAt);
        $response = $this->request($payload)->post($this->url());

        return ['status' => $response->status(), 'event_id' => $payload['event_id']];
    }

    /** 4.2(a): o MESMO event_id enviado N vezes em paralelo. */
    public function sendDuplicated(string $orderId, string $type, int $times): array
    {
        $payload = $this->payload($orderId, $type);

        $responses = Http::pool(fn (Pool $pool) => collect(range(1, max(1, $times)))
            ->map(fn ($i) => $this->signed($pool->as("r{$i}"), $payload)->post($this->url()))
            ->all());

        return [
            'event_id' => $payload['event_id'],
            'statuses' => collect($responses)->map(fn ($r) => $r instanceof ConnectionException ? 'erro' : $r->status())->values()->all(),
        ];
    }

    /** 4.2(b): `refunded` (mais novo) chega ANTES do `approved` (mais antigo). */
    public function sendOutOfOrder(string $orderId): array
    {
        $refund = $this->send($orderId, 'refunded', null, now()->toIso8601String());
        $approve = $this->send($orderId, 'approved', null, now()->subSeconds(10)->toIso8601String());

        return ['refund' => $refund, 'approve' => $approve];
    }

    /**
     * 4.2(c): o gateway desiste da resposta (timeout curto, resposta ignorada) e REENVIA o
     * mesmo aviso imediatamente, enquanto o servidor ainda pode estar processando o primeiro.
     */
    public function sendRetryOnTimeout(string $orderId, string $type = 'approved', float $giveUpAfterSeconds = 0.001): array
    {
        $payload = $this->payload($orderId, $type);

        $responses = Http::pool(fn (Pool $pool) => [
            $this->signed($pool->as('original')->timeout($giveUpAfterSeconds), $payload)->post($this->url()),
            $this->signed($pool->as('reenvio'), $payload)->post($this->url()),
        ]);

        $describe = fn ($r) => $r instanceof ConnectionException ? 'timeout (gateway desistiu)' : $r->status();

        return [
            'event_id' => $payload['event_id'],
            'original' => $describe($responses['original']),
            'reenvio' => $describe($responses['reenvio']),
        ];
    }

    private function url(): string
    {
        return config('services.gateway.webhook_url');
    }

    private function payload(string $orderId, string $type, ?string $eventId = null, ?string $occurredAt = null): array
    {
        return [
            'event_id' => $eventId ?? (string) Str::uuid(),
            'order_id' => $orderId,
            'type' => $type,
            'occurred_at' => $occurredAt ?? now()->toIso8601String(),
        ];
    }

    private function request(array $payload): PendingRequest
    {
        return $this->signed(Http::timeout(10), $payload);
    }

    /** @param  PendingRequest  $request */
    private function signed($request, array $payload)
    {
        $body = json_encode($payload);

        return $request->withBody($body, 'application/json')
            ->withHeaders(['X-Signature' => hash_hmac('sha256', $body, (string) config('services.gateway.secret'))]);
    }
}
