<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RaffleEntry extends Model
{
    protected $fillable = ['event_id', 'name', 'position'];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function raffleDraws(): HasMany
    {
        return $this->hasMany(RaffleDraw::class);
    }
}
