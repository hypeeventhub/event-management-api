<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NominationTopic extends Model
{
    public const STAGE_DRAFT = 'draft';

    public const STAGE_NOMINATION_ACTIVE = 'nomination_active';

    public const STAGE_VOTING_ACTIVE = 'voting_active';

    public const STAGE_CLOSED = 'closed';

    public const CATEGORIES = ['female', 'male'];

    protected $fillable = ['event_id', 'title', 'position', 'female_top_n', 'male_top_n', 'stage'];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'female_top_n' => 'integer',
            'male_top_n' => 'integer',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function subjects(): HasMany
    {
        return $this->hasMany(VotingSubject::class);
    }

    public function nominationRounds(): HasMany
    {
        return $this->subjects()->where('mode', 'nomination');
    }

    public function votingRounds(): HasMany
    {
        return $this->subjects()->where('mode', 'manual')->whereNotNull('generated_from_subject_id');
    }
}
