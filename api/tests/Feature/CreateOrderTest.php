<?php

use App\Actions\Orders\BatchInactiveException;
use App\Actions\Orders\CreateOrder;
use App\Actions\Orders\IdempotencyPayloadMismatchException;
use App\Actions\Orders\SoldOutException;
use App\Models\Batch;
use App\Models\Order;
use Illuminate\Support\Str;

function postOrder($test, array $payload, ?string $key = 'key-1')
{
    return $test->postJson('/api/v1/orders', $payload, array_filter(['Idempotency-Key' => $key]));
}

it('T12: o comprador compra SEM login e sem tickets[] (titular = comprador)', function () {
    $batch = Batch::factory()->create(['total' => 10, 'price_cents' => 15000]);

    $response = postOrder($this, buyerPayload($batch->id, 2))->assertCreated();

    $response->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.quantity', 2)
        ->assertJsonPath('data.total_cents', 30000);

    $order = Order::findOrFail($response->json('data.id'));
    expect($order->user_id)->toBeNull();
    expect($batch->fresh()->reserved)->toBe(2);
});

it('aceita tickets[] opcional, mas exige que o tamanho bata com a quantidade', function () {
    $batch = Batch::factory()->create();
    $holders = [['name' => 'Ana', 'email' => 'ana@example.com'], ['name' => 'Bia', 'email' => 'bia@example.com']];

    postOrder($this, buyerPayload($batch->id, 2, ['tickets' => $holders]), 'k-ok')->assertCreated();
    postOrder($this, buyerPayload($batch->id, 3, ['tickets' => $holders]), 'k-bad')->assertStatus(422);
});

it('valida CPF, e-mail, quantidade e exige Idempotency-Key', function () {
    $batch = Batch::factory()->create();

    $bad = buyerPayload($batch->id);
    $bad['buyer']['document'] = '11111111111';
    postOrder($this, $bad, 'k1')->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');

    postOrder($this, buyerPayload($batch->id, 99), 'k2')->assertStatus(422);
    postOrder($this, buyerPayload($batch->id), null)->assertStatus(400)->assertJsonPath('error.code', 'idempotency_key_required');
});

it('T2: mesma Idempotency-Key => 1 pedido, mesmo corpo, 200 + Idempotent-Replay', function () {
    $batch = Batch::factory()->create(['total' => 5]);

    $first = postOrder($this, buyerPayload($batch->id, 2), 'dup-click')->assertCreated();
    $second = postOrder($this, buyerPayload($batch->id, 2), 'dup-click')->assertOk()->assertHeader('Idempotent-Replay', 'true');

    expect($second->json('data'))->toEqual($first->json('data'));
    expect(Order::count())->toBe(1);
    expect($batch->fresh()->reserved)->toBe(2); // não reservou de novo
});

it('T2: a ordem das chaves do JSON não muda o hash (replay legítimo)', function () {
    $batch = Batch::factory()->create();
    $a = ['batch_id' => $batch->id, 'quantity' => 1, 'buyer' => ['name' => 'A', 'email' => 'a@a.com', 'document' => TEST_CPF]];
    $b = ['buyer' => ['document' => TEST_CPF, 'email' => 'a@a.com', 'name' => 'A'], 'quantity' => 1, 'batch_id' => $batch->id];

    postOrder($this, $a, 'same')->assertCreated();
    postOrder($this, $b, 'same')->assertOk();
    expect(Order::count())->toBe(1);
});

it('T2: mesma chave com payload diferente => 422 e nada é reservado', function () {
    $batch = Batch::factory()->create(['total' => 10]);

    postOrder($this, buyerPayload($batch->id, 1), 'reuse')->assertCreated();
    postOrder($this, buyerPayload($batch->id, 3), 'reuse')->assertStatus(422)->assertJsonPath('error.code', 'idempotency_payload_mismatch');

    expect(Order::count())->toBe(1);
    expect($batch->fresh()->reserved)->toBe(1);

    expect(fn () => app(CreateOrder::class)->execute('reuse', buyerPayload($batch->id, 3)))
        ->toThrow(IdempotencyPayloadMismatchException::class);
});

it('lote esgotado responde 409 e nunca passa do total (3.4)', function () {
    $batch = Batch::factory()->create(['total' => 3]);

    postOrder($this, buyerPayload($batch->id, 2), 'a')->assertCreated();
    postOrder($this, buyerPayload($batch->id, 2), 'b')->assertStatus(409)->assertJsonPath('error.code', 'sold_out');
    postOrder($this, buyerPayload($batch->id, 1), 'c')->assertCreated();
    postOrder($this, buyerPayload($batch->id, 1), 'd')->assertStatus(409);

    $fresh = $batch->fresh();
    expect($fresh->reserved + $fresh->sold)->toBe(3);
    expect(fn () => app(CreateOrder::class)->execute('e', buyerPayload($batch->id)))->toThrow(SoldOutException::class);
});

it('T6/B25: lote inativo não é comprável (409 batch_inactive)', function () {
    $batch = Batch::factory()->create(['total' => 10, 'is_active' => false]);

    postOrder($this, buyerPayload($batch->id), 'inactive')->assertStatus(409)->assertJsonPath('error.code', 'batch_inactive');

    expect(Order::count())->toBe(0);
    expect($batch->fresh()->reserved)->toBe(0);
    expect(fn () => app(CreateOrder::class)->execute('x', buyerPayload($batch->id)))->toThrow(BatchInactiveException::class);
});

it('B26: o rate limit é configurável e 0 desliga', function () {
    $batch = Batch::factory()->create(['total' => 100]);

    config(['tickets.orders_rate_limit_per_minute' => 0]);
    foreach (range(1, 8) as $i) {
        postOrder($this, buyerPayload($batch->id), "free-$i")->assertCreated();
    }

    config(['tickets.orders_rate_limit_per_minute' => 2]);
    app('cache')->store()->clear();
    postOrder($this, buyerPayload($batch->id), 'lim-1')->assertCreated();
    postOrder($this, buyerPayload($batch->id), 'lim-2')->assertCreated();
    postOrder($this, buyerPayload($batch->id), 'lim-3')->assertStatus(429)->assertJsonPath('error.code', 'rate_limited');
});

it('B28: GET /orders/{id} é público e não expõe documento nem e-mail completo', function () {
    $batch = Batch::factory()->create();
    $id = postOrder($this, buyerPayload($batch->id), 'show')->json('data.id');

    $response = $this->getJson("/api/v1/orders/{$id}")->assertOk();

    expect(json_encode($response->json()))->not->toContain(TEST_CPF)->not->toContain('maria@example.com');
    $response->assertJsonPath('data.buyer_email', 'ma***@example.com');
    $response->assertJsonMissingPath('data.buyer_document');

    $this->getJson('/api/v1/orders/'.Str::uuid())->assertNotFound();
});
