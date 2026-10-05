<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * event_id é a chave primária: um insertOrIgnore nela é o que garante que um
 * mesmo aviso do gateway, reenviado N vezes (item 4.2 do desafio), só produza
 * efeito uma vez (P5/P6).
 */
class PaymentEvent extends Model
{
    public $incrementing = false;

    protected $primaryKey = 'event_id';

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = ['event_id', 'order_id', 'type', 'occurred_at', 'received_at', 'payload'];

    protected $casts = [
        'occurred_at' => 'datetime',
        'received_at' => 'datetime',
        'payload' => 'array',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
