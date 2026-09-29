<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Event extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'created_by',
        'title',
        'slug',
        'description',
        'category',
        'capacity',
        'venue',
        'meeting_url',
        'starts_at',
        'ends_at',
        'timezone',
        'registration_opens_at',
        'registration_closes_at',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'registration_opens_at' => 'datetime',
            'registration_closes_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function registrationForms(): HasMany
    {
        return $this->hasMany(RegistrationForm::class);
    }

    public function activeRegistrationForm(): HasOne
    {
        return $this->hasOne(RegistrationForm::class)
            ->where('is_active', true)
            ->latestOfMany('version');
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(Registration::class);
    }

    public function raffleDraws(): HasMany
    {
        return $this->hasMany(RaffleDraw::class);
    }

    public function raffleEntries(): HasMany
    {
        return $this->hasMany(RaffleEntry::class);
    }

    public function raffleSetting(): HasOne
    {
        return $this->hasOne(EventRaffleSetting::class);
    }

    public function raffleWinners(): HasMany
    {
        return $this->hasMany(RaffleWinner::class);
    }

    public function emailInvitations(): HasMany
    {
        return $this->hasMany(EmailInvitation::class);
    }

    public function checkIns(): HasManyThrough
    {
        return $this->hasManyThrough(CheckIn::class, Registration::class);
    }

    public function acceptedCheckIns(): HasManyThrough
    {
        return $this->checkIns()->where('check_ins.result', 'accepted');
    }
}
