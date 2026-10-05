<?php

namespace App\Console\Commands\Gateway;

use Illuminate\Console\Command;

class OutOfOrder extends Command
{
    protected $signature = 'gateway:out-of-order {order_id}';

    protected $description = 'Simula o gateway entregando "refunded" antes de "approved" (P5).';

    public function handle(GatewayEventDispatcher $dispatcher): int
    {
        $result = $dispatcher->sendOutOfOrder($this->argument('order_id'));
        $this->info('Resultado: '.json_encode($result));

        return self::SUCCESS;
    }
}
