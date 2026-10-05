<?php

namespace App\Console\Commands\Gateway;

use Illuminate\Console\Command;

class Approve extends Command
{
    protected $signature = 'gateway:approve {order_id}';

    protected $description = 'Simula o gateway enviando um webhook "approved" para o pedido.';

    public function handle(GatewayEventDispatcher $dispatcher): int
    {
        $result = $dispatcher->send($this->argument('order_id'), 'approved');
        $this->info('Enviado: '.json_encode($result));

        return self::SUCCESS;
    }
}
