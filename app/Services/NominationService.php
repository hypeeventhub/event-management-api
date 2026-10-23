<?php

namespace App\Services;

use App\Events\NominationClosed;
use App\Models\Event;
use App\Models\VotingSubject;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class NominationService
{
    public function store(Event $event, array $data): VotingSubject
    {
        return DB::transaction(function () use ($event, $data): VotingSubject {
            $subject = $event->votingSubjects()->create([
                'slug' => $this->newSlug(),
                'title' => $data['title'],
                'mode' => 'nomination',
                'status' => VotingSubject::STATUS_DRAFT,
                'top_n' => $data['top_n'],
            ]);

            return $subject;
        });
    }

    public function update(Event $event, VotingSubject $subject, array $data): VotingSubject
    {
        return DB::transaction(function () use ($event, $subject, $data): VotingSubject {
            $record = $this->lockedNomination($event, $subject);
            abort_unless($record->status === VotingSubject::STATUS_DRAFT, 409, 'Only draft nominations can be edited.');

            $record->update(['title' => $data['title'], 'top_n' => $data['top_n']]);

            return $record;
        });
    }

    public function open(Event $event, VotingSubject $subject): VotingSubject
    {
        return DB::transaction(function () use ($event, $subject): VotingSubject {
            $record = $this->lockedNomination($event, $subject);
            abort_unless($record->status === VotingSubject::STATUS_DRAFT, 409, 'Only draft nominations can be opened.');
            $record->update(['status' => VotingSubject::STATUS_ACTIVE]);

            return $record;
        });
    }

    public function close(Event $event, VotingSubject $subject): VotingSubject
    {
        return DB::transaction(function () use ($event, $subject): VotingSubject {
            // Public voting takes the same lock before inserting a vote.
            $record = $this->lockedNomination($event, $subject);
            abort_unless($record->status === VotingSubject::STATUS_ACTIVE, 409, 'Only open nominations can be closed.');

            $ranked = $record->contestants()->withCount('votes')
                ->orderByDesc('votes_count')->orderBy('display_order')->orderBy('id')->get();
            abort_if($ranked->sum('votes_count') === 0, 409, 'A nomination with no votes cannot be closed.');
            abort_if($ranked->count() < 2, 409, 'At least two unique nominees are required to create a voting draft.');

            $cutoff = $ranked[min($record->top_n, $ranked->count()) - 1]->votes_count;
            $generated = $event->votingSubjects()->create([
                'slug' => $this->newSlug(),
                'title' => $record->title,
                'mode' => 'manual',
                'status' => VotingSubject::STATUS_DRAFT,
                'generated_from_subject_id' => $record->id,
            ]);
            foreach ($ranked->where('votes_count', '>=', $cutoff)->values() as $order => $finalist) {
                $generated->contestants()->create(['name' => $finalist->name, 'display_order' => $order]);
            }

            $record->update(['status' => VotingSubject::STATUS_CLOSED]);
            event(new NominationClosed($record));

            return $record;
        });
    }

    private function lockedNomination(Event $event, VotingSubject $subject): VotingSubject
    {
        return $event->nominationSubjects()->whereKey($subject->id)->lockForUpdate()->firstOrFail();
    }

    private function newSlug(): string
    {
        do {
            $slug = hash('sha256', Str::random(64));
        } while (VotingSubject::where('slug', $slug)->exists());

        return $slug;
    }
}
