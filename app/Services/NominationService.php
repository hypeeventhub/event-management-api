<?php

namespace App\Services;

use App\Events\NominationClosed;
use App\Events\VotingClosed;
use App\Models\Event;
use App\Models\NominationTopic;
use App\Models\VotingSubject;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class NominationService
{
    public function store(Event $event, array $data): VotingSubject
    {
        return DB::transaction(fn (): VotingSubject => $this->createSubject($event, [
            'title' => $data['title'],
            'mode' => 'nomination',
            'status' => VotingSubject::STATUS_DRAFT,
            'top_n' => $data['top_n'],
        ]));
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

            $finalists = $this->rankFinalists($record);
            $this->closeRound($event, $record, $finalists, VotingSubject::STATUS_DRAFT);

            return $record;
        });
    }

    public function storeTopics(Event $event, array $data): Collection
    {
        return DB::transaction(function () use ($event, $data): Collection {
            $this->lockEvent($event);
            $position = (int) $event->nominationTopics()->max('position');
            $topics = new Collection;

            foreach ($data as $details) {
                $topic = $event->nominationTopics()->create([
                    ...$details,
                    'position' => ++$position,
                    'stage' => NominationTopic::STAGE_DRAFT,
                ]);

                foreach (NominationTopic::CATEGORIES as $category) {
                    $this->createSubject($event, [
                        'nomination_topic_id' => $topic->id,
                        'category' => $category,
                        'title' => $topic->title,
                        'mode' => 'nomination',
                        'status' => VotingSubject::STATUS_DRAFT,
                        'top_n' => $details[$category.'_top_n'],
                    ]);
                }

                $topics->push($topic);
            }

            return $topics;
        }, 3);
    }

    public function openTopic(Event $event, NominationTopic $topic): NominationTopic
    {
        return DB::transaction(function () use ($event, $topic): NominationTopic {
            $this->lockEvent($event);
            $record = $this->lockedTopic($event, $topic->id);
            abort_unless($record->stage === NominationTopic::STAGE_DRAFT, 409, 'Only draft topics can be opened.');

            $earlierUnfinishedTopic = $event->nominationTopics()
                ->where('position', '<', $record->position)
                ->where('stage', '!=', NominationTopic::STAGE_CLOSED)
                ->lockForUpdate()
                ->first(['id']);
            abort_if($earlierUnfinishedTopic, 409, 'Finish the earlier topics before opening this one.');

            $activeTopic = $event->nominationTopics()
                ->whereIn('stage', [NominationTopic::STAGE_NOMINATION_ACTIVE, NominationTopic::STAGE_VOTING_ACTIVE])
                ->lockForUpdate()
                ->first();
            abort_if($activeTopic, 409, 'Close the active topic before opening another.');

            $rounds = $this->lockedCategoryRounds($record->nominationRounds());
            abort_unless(
                $rounds->every(fn (VotingSubject $round): bool => $round->status === VotingSubject::STATUS_DRAFT),
                409,
                'Both nomination rounds must be drafts.',
            );

            foreach ($rounds as $round) {
                $round->update(['status' => VotingSubject::STATUS_ACTIVE]);
            }
            $record->update(['stage' => NominationTopic::STAGE_NOMINATION_ACTIVE]);

            return $record;
        }, 3);
    }

    public function closeTopicNominations(Event $event, NominationTopic $topic): NominationTopic
    {
        return DB::transaction(function () use ($event, $topic): NominationTopic {
            $this->lockEvent($event);
            $record = $this->lockedTopic($event, $topic->id);

            // A retried close must return the existing generated rounds.
            if (in_array($record->stage, [NominationTopic::STAGE_VOTING_ACTIVE, NominationTopic::STAGE_CLOSED], true)) {
                return $record;
            }

            abort_unless($record->stage === NominationTopic::STAGE_NOMINATION_ACTIVE, 409, 'Only open nominations can be closed.');
            $rounds = $this->lockedCategoryRounds($record->nominationRounds());
            abort_unless(
                $rounds->every(fn (VotingSubject $round): bool => $round->status === VotingSubject::STATUS_ACTIVE),
                409,
                'Both nomination rounds must be active.',
            );

            $finalists = $rounds->map(fn (VotingSubject $round): Collection => $this->rankFinalists($round));
            foreach ($rounds as $category => $round) {
                $this->closeRound($event, $round, $finalists[$category], VotingSubject::STATUS_ACTIVE);
            }
            $record->update(['stage' => NominationTopic::STAGE_VOTING_ACTIVE]);

            return $record;
        }, 3);
    }

    public function closeTopicBallot(Event $event, VotingSubject $subject): VotingSubject
    {
        return DB::transaction(function () use ($event, $subject): VotingSubject {
            $this->lockEvent($event);
            $topic = $this->lockedTopic($event, $subject->nomination_topic_id);
            $ballots = $this->lockedCategoryRounds($topic->votingRounds());
            $record = $ballots->first(fn (VotingSubject $ballot): bool => $ballot->id === $subject->id);
            abort_unless($record, 404);
            abort_unless($record->status === VotingSubject::STATUS_ACTIVE, 409, 'Only active voting subjects can be closed.');

            $record->update(['status' => VotingSubject::STATUS_CLOSED]);
            if ($ballots->every(fn (VotingSubject $ballot): bool => $ballot->status === VotingSubject::STATUS_CLOSED)) {
                $topic->update(['stage' => NominationTopic::STAGE_CLOSED]);
            }
            event(new VotingClosed($record));

            return $record;
        }, 3);
    }

    public function closeTopicVoting(Event $event, NominationTopic $topic): NominationTopic
    {
        return DB::transaction(function () use ($event, $topic): NominationTopic {
            $this->lockEvent($event);
            $record = $this->lockedTopic($event, $topic->id);
            abort_unless($record->stage === NominationTopic::STAGE_VOTING_ACTIVE, 409, 'Only open topic voting can be closed.');

            $ballots = $this->lockedCategoryRounds($record->votingRounds());
            abort_unless(
                $ballots->every(fn (VotingSubject $ballot): bool => in_array($ballot->status, [VotingSubject::STATUS_ACTIVE, VotingSubject::STATUS_CLOSED], true)),
                409,
                'Both finalist ballots must be open or already closed.',
            );

            foreach ($ballots->where('status', VotingSubject::STATUS_ACTIVE) as $ballot) {
                $ballot->update(['status' => VotingSubject::STATUS_CLOSED]);
                event(new VotingClosed($ballot));
            }

            $record->update(['stage' => NominationTopic::STAGE_CLOSED]);

            return $record;
        }, 3);
    }

    private function rankFinalists(VotingSubject $round): Collection
    {
        $ranked = $round->contestants()->withCount('votes')
            ->when($round->nomination_topic_id !== null, fn ($query) => $query->has('votes'))
            ->orderByDesc('votes_count')->orderBy('display_order')->orderBy('id')->get();

        if ($round->nomination_topic_id !== null) {
            $category = ucfirst($round->category);
            abort_if($ranked->count() < 2, 409, "{$category} nominations need at least two distinct nominees.");
        } else {
            abort_if($ranked->sum('votes_count') === 0, 409, 'A nomination with no votes cannot be closed.');
            abort_if($ranked->count() < 2, 409, 'At least two unique nominees are required to create a voting draft.');
        }

        $cutoff = $ranked[min($round->top_n, $ranked->count()) - 1]->votes_count;

        return $ranked->where('votes_count', '>=', $cutoff)->values();
    }

    private function closeRound(Event $event, VotingSubject $round, Collection $finalists, string $ballotStatus): void
    {
        $generated = $this->createSubject($event, [
            'title' => $round->title,
            'mode' => 'manual',
            'status' => $ballotStatus,
            'generated_from_subject_id' => $round->id,
            'nomination_topic_id' => $round->nomination_topic_id,
            'category' => $round->category,
        ]);
        foreach ($finalists as $order => $finalist) {
            $generated->contestants()->create(['name' => $finalist->name, 'display_order' => $order]);
        }

        $round->update(['status' => VotingSubject::STATUS_CLOSED]);
        event(new NominationClosed($round));
    }

    private function createSubject(Event $event, array $data): VotingSubject
    {
        return $event->votingSubjects()->create(['slug' => $this->newSlug(), ...$data]);
    }

    private function lockEvent(Event $event): void
    {
        // Serialize topic creation and phase changes, including when no topic is active.
        Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();
    }

    private function lockedTopic(Event $event, int $topicId): NominationTopic
    {
        return $event->nominationTopics()->whereKey($topicId)->lockForUpdate()->firstOrFail();
    }

    private function lockedCategoryRounds(HasMany $query): Collection
    {
        $rounds = $query->whereIn('category', NominationTopic::CATEGORIES)
            ->orderBy('id')->lockForUpdate()->get()->keyBy('category');
        abort_unless($rounds->has(NominationTopic::CATEGORIES), 409, 'Both category rounds are required.');

        return $rounds;
    }

    private function lockedNomination(Event $event, VotingSubject $subject): VotingSubject
    {
        return $event->nominationSubjects()->whereNull('nomination_topic_id')
            ->whereKey($subject->id)->lockForUpdate()->firstOrFail();
    }

    private function newSlug(): string
    {
        do {
            $slug = hash('sha256', Str::random(64));
        } while (VotingSubject::where('slug', $slug)->exists());

        return $slug;
    }
}
