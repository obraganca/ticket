<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tabela outbox: fonte da verdade dos efeitos pós-pagamento (P4/P6). Gravada
 * na MESMA transação do webhook; o dispatch da fila é só um "toque" para
 * acelerar o processamento — se ele se perder (Redis caiu entre o commit e o
 * dispatch), o comando SweepOutbox (agendado) reenfileira qualquer job
 * `pending` vencido ou `running` com lock expirado. Nenhuma venda se perde.
 */
class OutboxJob extends Model
{
    public const KIND_RECEIPT_EMAIL = 'send_receipt_email';

    public const KIND_TICKETS_EMAIL = 'send_tickets_email';

    public const KIND_REGISTER_FINANCE = 'register_finance';

    public const KIND_REGISTER_FINANCE_REFUND = 'register_finance_refund';

    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_DONE = 'done';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'order_id', 'kind', 'status', 'attempts', 'next_run_at',
        'locked_until', 'last_error',
    ];

    protected $casts = [
        'next_run_at' => 'datetime',
        'locked_until' => 'datetime',
        'attempts' => 'integer',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function queueName(): string
    {
        return in_array($this->kind, [self::KIND_RECEIPT_EMAIL, self::KIND_TICKETS_EMAIL], true)
            ? 'mail'
            : 'finance';
    }
}
