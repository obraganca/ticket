<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Requests\Admin\StoreTicketTypeRequest;
use App\Http\Requests\Admin\UpdateTicketTypeRequest;
use App\Models\Event;
use App\Models\TicketType;
use Illuminate\Support\Facades\Cache;

class AdminTicketTypeController
{
    public function store(StoreTicketTypeRequest $request, Event $event)
    {
        $ticketType = $event->ticketTypes()->create($request->validated());

        $this->flushCaches($event->id);

        return response()->json(['data' => $this->serialize($ticketType)], 201);
    }

    public function update(UpdateTicketTypeRequest $request, TicketType $ticketType)
    {
        $ticketType->fill($request->validated());
        $ticketType->save();

        $this->flushCaches($ticketType->event_id);

        return response()->json(['data' => $this->serialize($ticketType->fresh('batches'))]);
    }

    private function flushCaches(int $eventId): void
    {
        Cache::forget('public:events');
        Cache::forget("public:events:{$eventId}:batches");
        Cache::forget('admin:dashboard:snapshot');
    }

    private function serialize(TicketType $ticketType): array
    {
        return [
            'id' => $ticketType->id,
            'event_id' => $ticketType->event_id,
            'name' => $ticketType->name,
            'batches' => $ticketType->batches->map(fn ($b) => [
                'id' => $b->id,
                'ticket_type_id' => $b->ticket_type_id,
                'name' => $b->name,
                'price_cents' => $b->price_cents,
                'total' => $b->total,
                'sold' => $b->sold,
                'reserved' => $b->reserved,
                'available' => $b->available(),
                'is_active' => $b->is_active,
                'created_at' => $b->created_at->toIso8601String(),
            ])->values(),
        ];
    }
}
