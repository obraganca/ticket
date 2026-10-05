<?php

use App\Models\Batch;
use App\Models\Event;
use App\Models\OutboxJob;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

function dashboard($test)
{
    Cache::flush(); // o painel guarda ~1s em cache; o teste precisa ver cada mudança
    $test->actingAs(User::factory()->admin()->create(), 'sanctum');

    return $test->getJson('/api/v1/admin/dashboard')->assertOk();
}

function batchRow($response, int $batchId): array
{
    return collect($response->json('events'))->flatMap(fn ($e) => $e['batches'])->firstWhere('id', $batchId);
}

it('T10: números corretos por lote e por evento em todo o ciclo de vida', function () {
    $event = Event::factory()->create();
    $a = Batch::factory()->create(['event_id' => $event->id, 'total' => 50, 'price_cents' => 10000]);
    $b = Batch::factory()->create(['event_id' => $event->id, 'total' => 20, 'price_cents' => 25000]);

    expect(batchRow(dashboard($this), $a->id))->toMatchArray(['sold' => 0, 'pending' => 0, 'available' => 50, 'revenue_cents' => 0]);

    // compras: A 3 (pago), A 2 (aguardando), A 4 (pago e depois reembolsado), B 5 (vai expirar)
    $paid = placeOrder($a, 3);
    placeOrder($a, 2);
    $refunded = placeOrder($a, 4);
    $expiring = placeOrder($b, 5);
    expect(batchRow(dashboard($this), $a->id))->toMatchArray(['sold' => 0, 'pending' => 9, 'available' => 41]);

    gatewayEvent($paid, 'approved');
    gatewayEvent($refunded, 'approved');
    expect(batchRow(dashboard($this), $a->id))->toMatchArray(['sold' => 7, 'pending' => 2, 'available' => 41, 'revenue_cents' => 70000]);

    gatewayEvent($refunded, 'refunded');
    expect(batchRow(dashboard($this), $a->id))->toMatchArray(['sold' => 3, 'pending' => 2, 'available' => 45, 'revenue_cents' => 30000]);

    $expiring->update(['expires_at' => now()->subMinute()]);
    $this->artisan('orders:expire');
    $response = dashboard($this);
    expect(batchRow($response, $b->id))->toMatchArray(['sold' => 0, 'pending' => 0, 'available' => 20]);

    $totals = collect($response->json('events'))->firstWhere('id', $event->id)['totals'];
    expect($totals)->toMatchArray(['sold' => 3, 'pending' => 2, 'available' => 65, 'revenue_cents' => 30000]);

    // invariante: sold + pending + available == total, sempre
    foreach ([$a, $b] as $batch) {
        $row = batchRow($response, $batch->id);
        expect($row['sold'] + $row['pending'] + $row['available'])->toBe($row['total']);
    }
});

it('T10/P7: o painel não consulta a tabela orders (custo independe do nº de pedidos)', function () {
    $batch = Batch::factory()->create();
    placeOrder($batch);
    Cache::flush();
    $this->actingAs(User::factory()->admin()->create(), 'sanctum');

    DB::enableQueryLog();
    $this->getJson('/api/v1/admin/dashboard')->assertOk();
    $tables = collect(DB::getQueryLog())->pluck('query')->filter(fn ($q) => preg_match('/\b(from|join)\s+"?orders"?/i', $q));

    expect($tables)->toBeEmpty();
});

it('T10/P7: ETag devolve 304 quando nada mudou', function () {
    Batch::factory()->create();
    $this->actingAs(User::factory()->admin()->create(), 'sanctum');

    $etag = $this->getJson('/api/v1/admin/dashboard')->assertOk()->headers->get('ETag');

    $this->getJson('/api/v1/admin/dashboard', ['If-None-Match' => $etag])->assertStatus(304);
});

it('T10: sem token 401, autenticado não admin 403', function () {
    $this->getJson('/api/v1/admin/dashboard')->assertStatus(401);
    $this->actingAs(User::factory()->create(), 'sanctum')->getJson('/api/v1/admin/dashboard')->assertStatus(403);
});

it('T10: o painel mostra jobs failed do outbox', function () {
    $order = placeOrder(Batch::factory()->create());
    gatewayEvent($order, 'approved');
    OutboxJob::where('kind', 'register_finance')->update(['status' => 'failed']);

    expect(dashboard($this)->json('jobs.failed'))->toBe(1);
});
