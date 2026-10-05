<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * UNIQUE(order_id, kind). Só conta como "enviado" quando `sent_at` está preenchido
 * (a linha é criada antes da tentativa com sent_at nulo; ver ProcessOutboxJob::sendEmail).
 */
class EmailDelivery extends Model
{
    public $timestamps = false;

    protected $fillable = ['order_id', 'kind', 'message_id', 'sent_at', 'attempted_at'];

    protected $casts = ['sent_at' => 'datetime', 'attempted_at' => 'datetime'];
}
