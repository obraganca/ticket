<?php

namespace App\Actions\Orders;

use App\Domain\Orders\OrderStatus;
use App\Models\Batch;
use App\Models\IdempotencyKey;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class CreateOrder
{
    /**
     * @return array{order: Order, replay: bool}
     *
     * @throws SoldOutException
     * @throws BatchInactiveException
     * @throws IdempotencyPayloadMismatchException
     * @throws ConcurrentIdempotencyKeyInFlightException
     */
    public function execute(string $idempotencyKey, array $payload, ?int $userId = null): array
    {
        $requestHash = self::hashPayload($payload);
        $ttlMinutes = (int) config('tickets.reservation_ttl_minutes', 15);

        return DB::transaction(function () use ($idempotencyKey, $requestHash, $payload, $userId, $ttlMinutes) {
            // A PK de idempotency_keys é o mutex real: uma 2ª transação com a mesma
            // chave BLOQUEIA neste INSERT até a 1ª terminar (commit/rollback).
            $inserted = DB::table('idempotency_keys')->insertOrIgnore([
                'key' => $idempotencyKey,
                'request_hash' => $requestHash,
                'order_id' => null,
                'created_at' => now(),
            ]);

            if ($inserted === 0) {
                $existing = IdempotencyKey::findOrFail($idempotencyKey);

                if ($existing->request_hash !== $requestHash) {
                    throw new IdempotencyPayloadMismatchException;
                }

                if ($existing->order_id === null) {
                    throw new ConcurrentIdempotencyKeyInFlightException;
                }

                return ['order' => Order::findOrFail($existing->order_id), 'replay' => true];
            }

            $quantity = (int) $payload['quantity'];

            // Reserva atômica: o WHERE garante estoque e lote ativo; o CHECK
            // (reserved + sold <= total) no banco é a segunda linha de defesa.
            $affected = DB::table('batches')
                ->where('id', $payload['batch_id'])
                ->where('is_active', true)
                ->whereRaw('total - reserved - sold >= ?', [$quantity])
                ->update(['reserved' => DB::raw('reserved + '.$quantity)]);

            $batch = Batch::findOrFail($payload['batch_id']);

            if ($affected === 0) {
                throw $batch->is_active ? new SoldOutException : new BatchInactiveException;
            }

            $order = Order::create([
                'user_id' => $userId,
                'batch_id' => $batch->id,
                'quantity' => $quantity,
                'total_cents' => $batch->price_cents * $quantity,
                'status' => OrderStatus::PENDING,
                'buyer_name' => $payload['buyer']['name'],
                'buyer_email' => $payload['buyer']['email'],
                'buyer_document' => $payload['buyer']['document'],
                'ticket_holders' => $payload['tickets'] ?? null,
                'expires_at' => CarbonImmutable::now()->addMinutes($ttlMinutes),
            ]);

            DB::table('idempotency_keys')->where('key', $idempotencyKey)->update(['order_id' => $order->id]);

            return ['order' => $order, 'replay' => false];
        }, attempts: 3); // retry automático em deadlock
    }

    /** Hash estável: a ordem das chaves do JSON não pode mudar o resultado. */
    public static function hashPayload(array $payload): string
    {
        $normalize = function (mixed $value) use (&$normalize) {
            if (! is_array($value)) {
                return $value;
            }
            if (! array_is_list($value)) {
                ksort($value);
            }

            return array_map($normalize, $value);
        };

        return hash('sha256', json_encode($normalize($payload)));
    }
}
