<?php

namespace App\Console\Commands\Gateway;

use Illuminate\Console\Command;

class DuplicateEvent extends Command
{
    protected $signature = 'gateway:duplicate {order_id} {--times=5} {--type=approved}';

    protected $description = 'Simula o gateway reenviando o MESMO aviso várias vezes em paralelo (item 4.2).';

    public function handle(GatewayEventDispatcher $dispatcher): int
    {
        $result = $dispatcher->sendDuplicated(
            $this->argument('order_id'),
            $this->option('type'),
            (int) $this->option('times')
        );
        $this->info('Resultado: '.json_encode($result));

        return self::SUCCESS;
    }
}
