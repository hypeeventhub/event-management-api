<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class VotingSubject extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_CLOSED = 'closed';

    protected $fillable = [
        'event_id', 'slug', 'title', 'status', 'mode', 'top_n', 'generated_from_subject_id',
    ];

    protected static function booted(): void
    {
        static::deleting(function (self $subject): void {
            $subject->votes()->delete();
        });
    }

    protected function casts(): array
    {
        return [
            'top_n' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function contestants(): HasMany
    {
        return $this->hasMany(VotingContestant::class);
    }

    public function votes(): HasMany
    {
        return $this->hasMany(VotingVote::class);
    }

    public function generatedFromSubject(): BelongsTo
    {
        return $this->belongsTo(self::class, 'generated_from_subject_id');
    }

    public function generatedSubject(): HasOne
    {
        return $this->hasOne(self::class, 'generated_from_subject_id');
    }
}
