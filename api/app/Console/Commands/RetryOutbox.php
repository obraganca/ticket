<?php

namespace App\Console\Commands;

use App\Jobs\ProcessOutboxJob;
use App\Models\OutboxJob;
use Illuminate\Console\Command;

/**
 * Requisito 5.4: uma venda nunca fica sem registro. Jobs que esgotaram as tentativas
 * ficam `failed` (visíveis no painel); este comando os recoloca em `pending`.
 */
class RetryOutbox extends Command
{
    protected $signature = 'outbox:retry {--kind= : send_receipt_email|send_tickets_email|register_finance|register_finance_refund} {--order= : UUID do pedido} {--all-failed : todos os jobs failed}';

    protected $description = 'Recoloca jobs do outbox em failed de volta na fila (zera as tentativas).';

    public function handle(): int
    {
        if (! $this->option('kind') && ! $this->option('order') && ! $this->option('all-failed')) {
            $this->error('Informe --all-failed, --kind ou --order.');

            return self::FAILURE;
        }

        $query = OutboxJob::where('status', OutboxJob::STATUS_FAILED);

        if ($kind = $this->option('kind')) {
            $query->where('kind', $kind);
        }
        if ($order = $this->option('order')) {
            $query->where('order_id', $order);
        }

        $jobs = $query->get();

        foreach ($jobs as $job) {
            $job->update(['status' => OutboxJob::STATUS_PENDING, 'attempts' => 0, 'next_run_at' => now(), 'locked_until' => null]);
            ProcessOutboxJob::dispatch($job->id)->onQueue($job->queueName());
        }

        $this->info('Jobs recolocados na fila: '.$jobs->count());

        return self::SUCCESS;
    }
}
