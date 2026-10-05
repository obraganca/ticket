<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * `reserved` = ingressos em pedidos PENDING (ainda não pagos, ainda não expirados).
 * `sold`     = ingressos em pedidos PAID.
 * A constraint de banco `reserved + sold <= total` (ver migration) é a última
 * linha de defesa contra overselling; a UPDATE condicional em CreateOrder é a
 * primeira. Nenhuma das duas depende de lock em memória, então continua
 * correta com múltiplas réplicas do PHP-FPM (ver README, seção Concorrência).
 */
class Batch extends Model
{
    use HasFactory;

    protected $fillable = ['event_id', 'ticket_type_id', 'name', 'price_cents', 'total', 'reserved', 'sold', 'revenue_cents', 'is_active'];

    protected $casts = [
        'price_cents' => 'integer',
        'total' => 'integer',
        'reserved' => 'integer',
        'sold' => 'integer',
        'revenue_cents' => 'integer',
        'is_active' => 'boolean',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function available(): int
    {
        return max(0, $this->total - $this->reserved - $this->sold);
    }

    public function isPurchasable(): bool
    {
        return $this->is_active && $this->available() > 0;
    }

    public function ticketType(): BelongsTo
    {
        return $this->belongsTo(TicketType::class);
    }
}
