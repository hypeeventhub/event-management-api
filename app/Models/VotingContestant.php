<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VotingContestant extends Model
{
    protected $fillable = ['voting_subject_id', 'name', 'display_order'];

    public function subject(): BelongsTo
    {
        return $this->belongsTo(VotingSubject::class, 'voting_subject_id');
    }

    public function votes(): HasMany
    {
        return $this->hasMany(VotingVote::class);
    }
}
