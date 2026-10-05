<?php

namespace App\Models;

use App\Domain\Orders\OrderStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'user_id', 'batch_id', 'quantity', 'total_cents',
        'status', 'buyer_name', 'buyer_email',
        'buyer_document', 'ticket_holders', 'expires_at', 'finance_registered_at',
    ];

    protected $casts = [
        'status' => OrderStatus::class,
        'quantity' => 'integer',
        'total_cents' => 'integer',
        'ticket_holders' => 'array',
        'expires_at' => 'datetime',
        'finance_registered_at' => 'datetime',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    public function paymentEvents(): HasMany
    {
        return $this->hasMany(PaymentEvent::class);
    }

    public function outboxJobs(): HasMany
    {
        return $this->hasMany(OutboxJob::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
