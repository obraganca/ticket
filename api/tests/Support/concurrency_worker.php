<?php

/**
 * Processo-filho dos testes de concorrência. Cada um abre a SUA conexão com o Postgres
 * (banco *_test, herdado do ambiente do PHPUnit) e dispara as tentativas ao mesmo tempo,
 * esperando um instante comum (barreira) para maximizar a contenção.
 *
 * argv[1] = JSON {task, start_at, attempts: [...]}  |  stdout = 1 linha JSON por tentativa.
 */

use App\Actions\Orders\ApplyPaymentEvent;
use App\Actions\Orders\BatchInactiveException;
use App\Actions\Orders\ConcurrentIdempotencyKeyInFlightException;
use App\Actions\Orders\CreateOrder;
use App\Actions\Orders\SoldOutException;
use App\Domain\Orders\PaymentEventType;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$database = config('database.connections.pgsql.database');
if (! str_ends_with($database, '_test')) {
    fwrite(STDERR, "banco inválido: $database\n");
    exit(2);
}

$spec = json_decode($argv[1], true);
DB::select('select 1'); // conexão aberta ANTES da barreira
while (microtime(true) < $spec['start_at']) {
    usleep(100);
}

foreach ($spec['attempts'] as $attempt) {
    try {
        if ($spec['task'] === 'create_order') {
            $r = app(CreateOrder::class)->execute($attempt['key'], $attempt['payload']);
            $out = ['result' => $r['replay'] ? 'replay' : 'created', 'order_id' => $r['order']->id];
        } else {
            app(ApplyPaymentEvent::class)->execute(
                $attempt['event_id'], $attempt['order_id'], PaymentEventType::from($attempt['type']),
                new DateTimeImmutable($attempt['occurred_at'] ?? 'now')
            );
            $out = ['result' => 'applied'];
        }
    } catch (SoldOutException) {
        $out = ['result' => 'sold_out'];
    } catch (BatchInactiveException) {
        $out = ['result' => 'inactive'];
    } catch (ConcurrentIdempotencyKeyInFlightException) {
        $out = ['result' => 'in_flight'];
    } catch (Throwable $e) {
        $out = ['result' => 'error', 'message' => get_class($e).': '.$e->getMessage()];
    }
    echo json_encode($out)."\n";
}
