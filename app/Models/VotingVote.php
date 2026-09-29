<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VotingVote extends Model
{
    protected $fillable = ['event_id', 'voting_subject_id', 'voting_contestant_id', 'registration_id'];

    protected static function booted(): void
    {
        static::creating(function (self $vote): void {
            $vote->event_id = VotingSubject::query()
                ->whereKey($vote->voting_subject_id)
                ->value('event_id');
        });
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(VotingSubject::class, 'voting_subject_id');
    }

    public function contestant(): BelongsTo
    {
        return $this->belongsTo(VotingContestant::class, 'voting_contestant_id');
    }

    public function registration(): BelongsTo
    {
        return $this->belongsTo(Registration::class);
    }
}
