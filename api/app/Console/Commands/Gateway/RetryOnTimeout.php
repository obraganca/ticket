<?php

namespace App\Console\Commands\Gateway;

use Illuminate\Console\Command;

class RetryOnTimeout extends Command
{
    protected $signature = 'gateway:retry-on-timeout {order_id} {--type=approved}';

    protected $description = 'Simula o gateway que desiste após o timeout e reenvia o mesmo aviso (item 4.2c).';

    public function handle(GatewayEventDispatcher $dispatcher): int
    {
        $result = $dispatcher->sendRetryOnTimeout($this->argument('order_id'), $this->option('type'));
        $this->info('Resultado: '.json_encode($result, JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
