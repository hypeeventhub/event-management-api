<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventRaffleSetting extends Model
{
    protected $fillable = ['event_id', 'remove_winners', 'speed', 'theme'];

    protected function casts(): array
    {
        return ['remove_winners' => 'boolean', 'speed' => 'integer'];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}
