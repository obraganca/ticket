<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Um tipo de ingresso (ex.: "Pista", "Camarote") pertence a um evento e tem
 * sua própria sequência de lotes. A "virada de lote" (AdminBatchController::toggle)
 * garante no máximo 1 lote ativo por tipo — nunca mistura Pista com Camarote.
 */
class TicketType extends Model
{
    protected $fillable = ['event_id', 'name'];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function batches(): HasMany
    {
        return $this->hasMany(Batch::class)->orderBy('id');
    }
}
