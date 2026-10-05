<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Event extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'image_path'];

    // Mantém a chave "image_url" na API (contrato que o front já usa),
    // mas calculada em tempo de leitura em vez de congelada no banco.
    protected $appends = ['image_url'];

    protected $hidden = ['image_path'];

    public function getImageUrlAttribute(): ?string
    {
        return $this->image_path ? Storage::disk('public')->url($this->image_path) : null;
    }

    public function batches(): HasMany
    {
        return $this->hasMany(Batch::class);
    }

    public function ticketTypes(): HasMany
    {
        return $this->hasMany(TicketType::class);
    }
}
