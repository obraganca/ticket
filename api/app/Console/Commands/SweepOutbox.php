<?php

namespace App\Console\Commands;

use App\Jobs\ProcessOutboxJob;
use App\Models\OutboxJob;
use Illuminate\Console\Command;

/**
 * A cada 30s no scheduler. Cobre (a) o Redis ter caído entre o commit e o dispatch,
 * (b) jobs `running` cujo worker morreu (locked_until vencido). A tabela outbox_jobs é
 * a fonte da verdade; isto é só o "empurrão" que garante que nenhuma venda fica presa.
 *
 * Jobs `pending` só são reenfileirados depois de uma carência (padrão 60s), e o
 * next_run_at é "tocado" para não duplicar o dispatch a cada varredura quando a fila
 * está apenas cheia (ex.: financeiro lento na abertura das vendas).
 */
class SweepOutbox extends Command
{
    protected $signature = 'outbox:sweep {--limit=500}';

    protected $description = 'Reenfileira jobs do outbox perdidos (pending vencido ou running travado).';

    public function handle(): int
    {
        $grace = (int) config('tickets.outbox_sweep_grace_seconds', 60);

        $stuck = OutboxJob::query()
            ->where(function ($q) use ($grace) {
                $q->where('status', OutboxJob::STATUS_PENDING)->where('next_run_at', '<=', now()->subSeconds($grace));
            })
            ->orWhere(function ($q) {
                $q->where('status', OutboxJob::STATUS_RUNNING)->where('locked_until', '<', now());
            })
            ->limit((int) $this->option('limit'))
            ->get();

        foreach ($stuck as $job) {
            $job->update(['status' => OutboxJob::STATUS_PENDING, 'locked_until' => null, 'next_run_at' => now()]);
            ProcessOutboxJob::dispatch($job->id)->onQueue($job->queueName());
        }

        $this->info('Jobs reenfileirados pelo sweeper: '.$stuck->count());

        return self::SUCCESS;
    }
}
