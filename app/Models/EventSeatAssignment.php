<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventSeatAssignment extends Model
{
    protected $fillable = [
        'event_id', 'registration_id', 'table_number', 'seat_number', 'assigned_by',
    ];

    protected $hidden = ['assigned_by'];

    protected function casts(): array
    {
        return [
            'table_number' => 'integer',
            'seat_number' => 'integer',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function registration(): BelongsTo
    {
        return $this->belongsTo(Registration::class);
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
