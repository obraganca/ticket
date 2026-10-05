<?php

namespace App\Console\Commands\Gateway;

use Illuminate\Console\Command;

class Decline extends Command
{
    protected $signature = 'gateway:decline {order_id}';

    protected $description = 'Simula o gateway enviando um webhook "declined" para o pedido.';

    public function handle(GatewayEventDispatcher $dispatcher): int
    {
        $result = $dispatcher->send($this->argument('order_id'), 'declined');
        $this->info('Enviado: '.json_encode($result));

        return self::SUCCESS;
    }
}
