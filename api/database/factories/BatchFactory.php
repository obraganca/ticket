<?php

namespace Database\Factories;

use App\Models\Batch;
use App\Models\Event;
use App\Models\TicketType;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Batch> */
class BatchFactory extends Factory
{
    protected $model = Batch::class;

    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'ticket_type_id' => fn (array $attrs) => TicketType::create(['event_id' => $attrs['event_id'], 'name' => 'Tipo '.fake()->unique()->word()])->id,
            'name' => '1º lote',
            'price_cents' => 10000,
            'total' => 50,
            'reserved' => 0,
            'sold' => 0,
            'revenue_cents' => 0,
            'is_active' => true,
        ];
    }
}
