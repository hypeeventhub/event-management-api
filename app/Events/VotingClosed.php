<?php

namespace App\Events;

use App\Models\VotingSubject;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

class VotingClosed implements ShouldBroadcast, ShouldDispatchAfterCommit, ShouldRescue
{
    private int $subjectId;

    private int $eventId;

    public function __construct(VotingSubject $subject)
    {
        $this->subjectId = $subject->id;
        $this->eventId = $subject->event_id;
    }

    public function broadcastWhen(): bool
    {
        return VotingSubject::query()
            ->whereKey($this->subjectId)
            ->where('event_id', $this->eventId)
            ->where('mode', 'manual')
            ->where('status', VotingSubject::STATUS_CLOSED)
            ->exists();
    }

    public function broadcastOn(): array
    {
        // Current subscribers can receive the close notice; new subscriptions to closed ballots are rejected.
        return $this->broadcastWhen()
            ? [new PrivateChannel("events.{$this->eventId}.voting.{$this->subjectId}")]
            : [];
    }

    public function broadcastWith(): array
    {
        return [
            'event_id' => $this->eventId,
            'subject_id' => $this->subjectId,
            'status' => VotingSubject::STATUS_CLOSED,
        ];
    }
}
