<?php

namespace Database\Seeders;

use App\Models\Batch;
use App\Models\Event;
use App\Models\TicketType;
use App\Models\User;
use Illuminate\Database\Seeder;

/** Idempotente: pode rodar a cada boot sem duplicar nem zerar contadores dos lotes. */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => env('ADMIN_EMAIL', 'admin@codificar.dev')],
            ['name' => 'Admin Codificar', 'password' => env('ADMIN_PASSWORD', 'password'), 'role' => User::ROLE_ADMIN]
        );

        $event = Event::updateOrCreate(['name' => 'Festival BH 2026']);

        $pista = TicketType::updateOrCreate(['event_id' => $event->id, 'name' => 'Pista']);
        $camarote = TicketType::updateOrCreate(['event_id' => $event->id, 'name' => 'Camarote']);

        // Lote de EXATAMENTE 50 ingressos (requisito 2.3). `total` só é definido na criação
        // para não sobrescrever o estoque de um banco já em uso.
        $this->batch($event, $pista, '1º lote', 10000, 50);
        $this->batch($event, $camarote, '1º lote', 25000, 200);
    }

    private function batch(Event $event, TicketType $type, string $name, int $priceCents, int $total): void
    {
        $batch = Batch::firstOrNew(['event_id' => $event->id, 'ticket_type_id' => $type->id, 'name' => $name]);

        if (! $batch->exists) {
            $batch->total = $total;
        }

        $batch->price_cents = $priceCents;
        $batch->is_active = $batch->exists ? $batch->is_active : true;
        $batch->save();
    }
}
