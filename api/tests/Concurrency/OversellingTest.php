<?php

use App\Domain\Orders\OrderStatus;
use App\Models\Batch;
use App\Models\Order;
use App\Models\OutboxJob;
use App\Models\Ticket;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(fn () => truncateAll());
afterEach(fn () => truncateAll());

it('T1: lote de 50 com 300 tentativas em 30 processos => exatamente 50 pedidos e nenhum overselling', function () {
    $batch = Batch::factory()->create(['total' => 50]);

    $results = spawnWorkers('create_order', 30, fn (int $w) => array_map(
        fn (int $i) => ['key' => "w{$w}-{$i}", 'payload' => buyerPayload($batch->id, 1)],
        range(1, 10)
    ));

    $tally = tally($results);
    expect($results)->toHaveCount(300);
    expect($tally)->toBe(['created' => 50, 'sold_out' => 250]); // nenhum erro, nenhum replay
    expect(Order::count())->toBe(50);

    $fresh = $batch->fresh();
    expect($fresh->reserved)->toBe(50)->and($fresh->sold)->toBe(0);
    expect($fresh->reserved + $fresh->sold)->toBeLessThanOrEqual($fresh->total);
    expect((int) Order::sum('quantity'))->toBe($fresh->reserved); // contador == soma dos pedidos
});

it('T1: quantidades mistas (1 a 3) nunca ultrapassam o total e o contador bate com os pedidos', function () {
    $batch = Batch::factory()->create(['total' => 50]);

    $results = spawnWorkers('create_order', 20, fn (int $w) => array_map(
        fn (int $i) => ['key' => "m{$w}-{$i}", 'payload' => buyerPayload($batch->id, (($w + $i) % 3) + 1)],
        range(1, 10)
    ));

    expect(array_keys(tally($results)))->each->toBeIn(['created', 'sold_out']);
    $fresh = $batch->fresh();
    expect($fresh->reserved)->toBeLessThanOrEqual(50)->and($fresh->reserved)->toBeGreaterThan(46); // sobra no máximo 1-2 (quantidade > sobra)
    expect((int) Order::sum('quantity'))->toBe($fresh->reserved);
    expect(DB::table('batches')->whereRaw('reserved + sold > total')->count())->toBe(0);
});

it('T1: o CHECK do banco é a segunda linha de defesa (não aceita reserved + sold > total)', function () {
    $batch = Batch::factory()->create(['total' => 5]);

    expect(fn () => DB::table('batches')->where('id', $batch->id)->update(['reserved' => 6]))
        ->toThrow(QueryException::class);
});

it('T2: a MESMA Idempotency-Key em 20 processos simultâneos => 1 pedido; os demais são replays', function () {
    $batch = Batch::factory()->create(['total' => 50]);
    $payload = buyerPayload($batch->id, 2);

    $results = spawnWorkers('create_order', 20, fn () => [['key' => 'clique-duplo', 'payload' => $payload]]);

    $tally = tally($results);
    expect($tally['created'] ?? 0)->toBe(1);
    expect(($tally['replay'] ?? 0) + ($tally['in_flight'] ?? 0))->toBe(19);
    expect(Order::count())->toBe(1);
    expect($batch->fresh()->reserved)->toBe(2); // reservou uma vez só
    expect(collect($results)->pluck('order_id')->filter()->unique())->toHaveCount(1);
});

it('T3: o MESMO aviso de aprovação em 20 processos => 1 emissão e 3 jobs no outbox', function () {
    $batch = Batch::factory()->create(['total' => 50]);
    $order = placeOrder($batch, 2);
    $event = ['event_id' => 'evt-duplicado', 'order_id' => $order->id, 'type' => 'approved'];

    $results = spawnWorkers('apply_event', 20, fn () => [$event]);

    expect(tally($results))->toBe(['applied' => 20]);
    expect($order->fresh()->status)->toBe(OrderStatus::PAID);
    expect(Ticket::where('order_id', $order->id)->count())->toBe(2);
    expect(OutboxJob::where('order_id', $order->id)->count())->toBe(3);
    expect(DB::table('payment_events')->count())->toBe(1);
    expect($batch->fresh()->only(['reserved', 'sold']))->toBe(['reserved' => 0, 'sold' => 2]);

    // processamento pós-pagamento: 1 venda no financeiro, 2 e-mails
    $fakes = fakeInfra();
    drainOutbox();
    expect($fakes['finance']->calls)->toHaveCount(1);
    expect($fakes['notifier']->sent)->toHaveCount(2);
});

it('T3: avisos DIFERENTES (approved x N, refunded x N) disputando o mesmo pedido terminam consistentes', function () {
    $batch = Batch::factory()->create(['total' => 50]);
    $order = placeOrder($batch, 2);

    spawnWorkers('apply_event', 16, fn (int $w) => [[
        'event_id' => "evt-{$w}", 'order_id' => $order->id, 'type' => $w % 2 ? 'approved' : 'refunded',
    ]]);

    $fresh = $batch->fresh();
    $status = $order->fresh()->status;
    expect($status)->toBeIn([OrderStatus::PAID, OrderStatus::REFUNDED]);

    if ($status === OrderStatus::REFUNDED) {
        expect(Ticket::where('order_id', $order->id)->where('status', 'active')->count())->toBe(0);
        expect($fresh->only(['reserved', 'sold', 'revenue_cents']))->toBe(['reserved' => 0, 'sold' => 0, 'revenue_cents' => 0]);
    } else {
        expect($fresh->only(['reserved', 'sold']))->toBe(['reserved' => 0, 'sold' => 2]);
    }
    expect($fresh->reserved)->toBeGreaterThanOrEqual(0)->and($fresh->sold)->toBeGreaterThanOrEqual(0);
    expect(OutboxJob::where('order_id', $order->id)->where('kind', 'register_finance')->count())->toBeLessThanOrEqual(1);
});
