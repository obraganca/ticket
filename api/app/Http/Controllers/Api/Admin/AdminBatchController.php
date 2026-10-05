<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\ApiError;
use App\Http\Requests\Admin\StoreBatchRequest;
use App\Http\Requests\Admin\UpdateBatchRequest;
use App\Models\Batch;
use App\Models\TicketType;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class AdminBatchController
{
    public function store(StoreBatchRequest $request, TicketType $ticketType)
    {
        $data = $request->validated();
        $activate = $data['is_active'] ?? true;

        // Mesma regra do toggle(): garante no máximo 1 lote ativo por tipo de
        // ingresso. Sem isso, criar um lote novo já ativo deixava dois lotes
        // "em venda" ao mesmo tempo pro mesmo tipo (bug reproduzido nos dados).
        $batch = DB::transaction(function () use ($ticketType, $data, $activate) {
            if ($activate) {
                Batch::where('ticket_type_id', $ticketType->id)->update(['is_active' => false]);
            }

            return $ticketType->batches()->create([
                'event_id' => $ticketType->event_id,
                'name' => $data['name'],
                'price_cents' => $data['price_cents'],
                'total' => $data['total'],
                'is_active' => $activate,
            ]);
        });

        $this->flushCaches($ticketType->event_id);

        return response()->json(['data' => $this->serialize($batch)], 201);
    }

    public function update(UpdateBatchRequest $request, Batch $batch)
    {
        $data = $request->validated();

        if (isset($data['total']) && $data['total'] < ($batch->reserved + $batch->sold)) {
            return ApiError::make(
                'invalid_total',
                'O total não pode ser menor que a soma de reservados e vendidos ('.($batch->reserved + $batch->sold).').',
                422
            );
        }

        $batch->fill($data);
        $batch->save();

        $this->flushCaches($batch->event_id);

        return response()->json(['data' => $this->serialize($batch)]);
    }

    /**
     * Virada de lote: ativar um lote desativa automaticamente os outros
     * lotes DO MESMO TIPO de ingresso (nunca mexe em outro tipo). Garante
     * no máximo 1 lote ativo por tipo por vez.
     */
    public function toggle(Batch $batch)
    {
        if ($batch->is_active) {
            $batch->is_active = false;
            $batch->save();
        } else {
            DB::transaction(function () use ($batch) {
                Batch::where('ticket_type_id', $batch->ticket_type_id)
                    ->where('id', '!=', $batch->id)
                    ->update(['is_active' => false]);

                $batch->is_active = true;
                $batch->save();
            });
        }

        $this->flushCaches($batch->event_id);

        return response()->json(['data' => $this->serialize($batch)]);
    }

    private function flushCaches(int $eventId): void
    {
        Cache::forget('public:events');
        Cache::forget("public:events:{$eventId}:batches");
        Cache::forget('admin:dashboard:snapshot');
    }

    private function serialize(Batch $batch): array
    {
        return [
            'id' => $batch->id,
            'ticket_type_id' => $batch->ticket_type_id,
            'event_id' => $batch->event_id,
            'name' => $batch->name,
            'price_cents' => $batch->price_cents,
            'total' => $batch->total,
            'sold' => $batch->sold,
            'reserved' => $batch->reserved,
            'available' => $batch->available(),
            'is_active' => $batch->is_active,
            'created_at' => $batch->created_at->toIso8601String(),
        ];
    }
}
