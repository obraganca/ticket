<?php

namespace App\Http\Controllers\Api;

use App\Models\Event;
use App\Models\TicketType;
use Illuminate\Support\Facades\Cache;

class EventController
{
    public function index()
    {
        $data = Cache::flexible('public:events', [1, 5], function () {
            return Event::all()->map(fn ($e) => [
                'id' => $e->id,
                'name' => $e->name,
                'image_url' => $e->image_url,
            ]);
        });

        return response()->json(['data' => $data])->header('Cache-Control', 'public, max-age=1');
    }

    /**
     * Uma linha por TIPO de ingresso (Pista, Camarote...), sempre com o lote
     * atualmente ativo daquele tipo (no máximo 1, garantido pela "virada de
     * lote" em AdminBatchController::toggle). Tipo sem lote ativo aparece
     * como esgotado/indisponível em vez de sumir da lista.
     */
    public function batches(Event $event)
    {
        $data = Cache::flexible("public:events:{$event->id}:batches", [1, 5], function () use ($event) {
            return $event->ticketTypes()
                ->with(['batches' => fn ($q) => $q->where('is_active', true)])
                ->get()
                ->map(function (TicketType $type) {
                    $batch = $type->batches->first();

                    if (! $batch) {
                        return [
                            'ticket_type_id' => $type->id,
                            'name' => $type->name,
                            'id' => null,
                            'batch_label' => null,
                            'price_cents' => null,
                            'available' => 0,
                            'sold_out' => true,
                        ];
                    }

                    return [
                        'ticket_type_id' => $type->id,
                        'name' => $type->name,
                        'id' => $batch->id,
                        'batch_label' => $batch->name,
                        'price_cents' => $batch->price_cents,
                        'available' => $batch->available(),
                        'sold_out' => $batch->available() <= 0,
                    ];
                });
        });

        return response()->json(['data' => $data])->header('Cache-Control', 'public, max-age=1');
    }
}
