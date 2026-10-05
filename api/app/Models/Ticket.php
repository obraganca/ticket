<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Ticket extends Model
{
    use HasUuids;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INVALIDATED = 'invalidated';

    protected $fillable = ['order_id', 'code', 'status', 'holder_name', 'holder_email'];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public static function generateCode(): string
    {
        // Não sequencial (random_bytes), evita ingressos adivinháveis.
        return strtoupper(bin2hex(random_bytes(8)));
    }
}
