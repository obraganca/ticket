<?php

namespace App\Console\Commands\Gateway;

use Illuminate\Console\Command;

class Refund extends Command
{
    protected $signature = 'gateway:refund {order_id}';

    protected $description = 'Simula o gateway enviando um webhook "refunded" para o pedido.';

    public function handle(GatewayEventDispatcher $dispatcher): int
    {
        $result = $dispatcher->send($this->argument('order_id'), 'refunded');
        $this->info('Enviado: '.json_encode($result));

        return self::SUCCESS;
    }
}
