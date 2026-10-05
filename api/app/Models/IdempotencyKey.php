<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * `key` é PK. O INSERT ... ON CONFLICT DO NOTHING em CreateOrder usa o índice
 * único dessa PK como o mecanismo real de exclusão mútua entre requisições
 * concorrentes com a mesma Idempotency-Key (P3) — não é apenas uma checagem
 * "SELECT depois INSERT", que teria race condition.
 */
class IdempotencyKey extends Model
{
    public $incrementing = false;

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = ['key', 'request_hash', 'order_id', 'created_at'];
}
