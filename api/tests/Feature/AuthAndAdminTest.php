<?php

use App\Models\Batch;
use App\Models\Event;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

it('T12: login de admin devolve token Bearer e /me funciona', function () {
    $admin = User::factory()->admin()->create(['email' => 'root@codificar.dev']);

    $token = $this->postJson('/api/v1/login', ['email' => 'root@codificar.dev', 'password' => 'password'])
        ->assertOk()->assertJsonPath('data.user.email', 'root@codificar.dev')->json('data.token');

    expect($token)->toBeString();
    $this->withToken($token)->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.email', 'root@codificar.dev');
});

it('T12: credenciais erradas => 422 e sem token', function () {
    User::factory()->admin()->create(['email' => 'root@codificar.dev']);

    $this->postJson('/api/v1/login', ['email' => 'root@codificar.dev', 'password' => 'errada'])
        ->assertStatus(422)->assertJsonMissingPath('data.token');
});

it('T12: /login tem throttle (429 depois do limite)', function () {
    foreach (range(1, 5) as $_) {
        $this->postJson('/api/v1/login', ['email' => 'x@x.com', 'password' => 'nope'])->assertStatus(422);
    }
    $this->postJson('/api/v1/login', ['email' => 'x@x.com', 'password' => 'nope'])->assertStatus(429);
});

it('T12: logout revoga o token', function () {
    User::factory()->admin()->create(['email' => 'root@codificar.dev']);
    $token = $this->postJson('/api/v1/login', ['email' => 'root@codificar.dev', 'password' => 'password'])->json('data.token');

    $this->withToken($token)->postJson('/api/v1/logout')->assertOk();

    expect(PersonalAccessToken::count())->toBe(0);
});

it('T12: não existe cadastro público nem "meus pedidos"', function () {
    $this->postJson('/api/v1/register', ['name' => 'x', 'email' => 'x@x.com', 'password' => 'password'])->assertStatus(404);
    $this->getJson('/api/v1/me/orders')->assertStatus(404);
    $this->assertDatabaseCount('users', 0);
});

it('T12: POST /orders funciona sem nenhum token', function () {
    $batch = Batch::factory()->create();
    $this->postJson('/api/v1/orders', buyerPayload($batch->id), ['Idempotency-Key' => 'anon'])->assertCreated();
});

it('decisão 6: /admin sem token => 401; autenticado não admin => 403; admin => 200', function () {
    $this->getJson('/api/v1/admin/dashboard')->assertStatus(401);
    $this->postJson('/api/v1/admin/dev/gateway/approve', ['order_id' => (string) Str::uuid()])->assertStatus(401);

    $customer = User::factory()->create();
    $this->actingAs($customer, 'sanctum')->getJson('/api/v1/admin/dashboard')->assertStatus(403)->assertJsonPath('error.code', 'forbidden');
    $this->actingAs($customer, 'sanctum')->postJson('/api/v1/admin/dev/gateway/approve', ['order_id' => (string) Str::uuid()])->assertStatus(403);
    $this->actingAs($customer, 'sanctum')->getJson('/api/v1/admin/events')->assertStatus(403);

    $this->actingAs(User::factory()->admin()->create(), 'sanctum')->getJson('/api/v1/admin/dashboard')->assertOk();
});

it('decisão 8: CRUD admin de eventos/lotes continua funcionando (smoke)', function () {
    $this->actingAs(User::factory()->admin()->create(), 'sanctum');
    $event = Event::factory()->create(['name' => 'Show X']);

    $this->getJson('/api/v1/admin/events')->assertOk()->assertJsonFragment(['name' => 'Show X']);
    $this->getJson("/api/v1/admin/events/{$event->id}")->assertOk();
});

it('ferramentas de simulação ficam fora do ar com ENABLE_DEV_TOOLS=false', function () {
    config(['tickets.dev_tools_enabled' => false]);
    $this->actingAs(User::factory()->admin()->create(), 'sanctum')
        ->postJson('/api/v1/admin/dev/gateway/approve', ['order_id' => (string) Str::uuid()])->assertStatus(404);
});

it('B10: readyz responde no caminho correto', function () {
    $this->getJson('/api/v1/readyz')->assertOk()->assertJson(['status' => 'ready']);
    $this->getJson('/api/api/v1/readyz')->assertNotFound();
});

it('B5: o seeder deixa o evento com 2 lotes visíveis, um de exatamente 50', function () {
    $this->seed();
    $this->seed(); // idempotente

    $event = Event::firstOrFail();
    $batches = $this->getJson("/api/v1/events/{$event->id}/batches")->assertOk()->json('data');

    expect(Batch::count())->toBe(2)->and(User::where('role', 'admin')->count())->toBe(1);
    $available = collect($batches)->pluck('available')->sort()->values()->all();
    expect($available)->toBe([50, 200]); // os lotes aparecem na vitrine pública (B5)
    expect(Batch::orderBy('total')->pluck('total')->all())->toBe([50, 200]);
});

it('admin:create cria e atualiza a senha de um admin', function () {
    $this->artisan('admin:create', ['email' => 'novo@codificar.dev', '--name' => 'Novo', '--password' => 'senha-forte-1'])->assertSuccessful();
    expect(User::where('email', 'novo@codificar.dev')->first()->isAdmin())->toBeTrue();

    $this->artisan('admin:create', ['email' => 'novo@codificar.dev', '--password' => 'outra-senha-2'])->assertSuccessful();
    expect(User::where('email', 'novo@codificar.dev')->count())->toBe(1);
    expect(Hash::check('outra-senha-2', User::first()->password))->toBeTrue();

    $this->artisan('admin:create', ['email' => 'curta@codificar.dev', '--password' => '123'])->assertFailed();
});
