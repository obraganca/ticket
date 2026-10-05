<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Requests\Admin\StoreEventRequest;
use App\Http\Requests\Admin\UpdateEventRequest;
use App\Models\Event;
use App\Models\TicketType;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class AdminEventController
{
    public function index()
    {
        $events = Event::with(['ticketTypes.batches' => fn ($q) => $q->orderBy('id')])
            ->orderByDesc('id')
            ->get()
            ->map(fn (Event $event) => $this->serialize($event));

        return response()->json(['data' => $events]);
    }

    public function show(Event $event)
    {
        $event->load(['ticketTypes.batches' => fn ($q) => $q->orderBy('id')]);

        return response()->json(['data' => $this->serialize($event)]);
    }

    public function store(StoreEventRequest $request)
    {
        $event = Event::create(['name' => $request->validated()['name']]);

        if ($request->hasFile('image')) {
            $event->image_path = $this->storeImage($request);
            $event->save();
        }

        $this->flushPublicCaches();

        return response()->json(['data' => $this->serialize($event->fresh('ticketTypes.batches'))], 201);
    }

    public function update(UpdateEventRequest $request, Event $event)
    {
        $data = $request->validated();

        if (array_key_exists('name', $data)) {
            $event->name = $data['name'];
        }

        if ($request->boolean('remove_image')) {
            $this->deleteImage($event);
            $event->image_path = null;
        }

        if ($request->hasFile('image')) {
            $this->deleteImage($event);
            $event->image_path = $this->storeImage($request);
        }

        $event->save();
        $this->flushPublicCaches($event->id);

        return response()->json(['data' => $this->serialize($event->fresh('ticketTypes.batches'))]);
    }

    // Agora retorna só o caminho relativo (ex.: "events/arquivo.png"),
    // não a URL completa — a URL é calculada em Event::getImageUrlAttribute().
    private function storeImage(StoreEventRequest|UpdateEventRequest $request): string
    {
        return $request->file('image')->store('events', 'public');
    }

    private function deleteImage(Event $event): void
    {
        if ($event->image_path) {
            Storage::disk('public')->delete($event->image_path);
        }
    }

    private function flushPublicCaches(?int $eventId = null): void
    {
        Cache::forget('public:events');
        Cache::forget('admin:dashboard:snapshot');

        if ($eventId) {
            Cache::forget("public:events:{$eventId}:batches");
        }
    }

    private function serialize(Event $event): array
    {
        return [
            'id' => $event->id,
            'name' => $event->name,
            'image_url' => $event->getImageUrlAttribute(),
            'ticket_types' => $event->ticketTypes->map(fn (TicketType $type) => [
                'id' => $type->id,
                'event_id' => $type->event_id,
                'name' => $type->name,
                'batches' => $type->batches->map(fn ($b) => [
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
            ])->values(),
        ];
    }
}
