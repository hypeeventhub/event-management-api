<?php

namespace App\Events;

use App\Models\VotingSubject;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

class NominationClosed implements ShouldBroadcast, ShouldDispatchAfterCommit, ShouldRescue
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
        return VotingSubject::whereKey($this->subjectId)
            ->where('event_id', $this->eventId)
            ->where('mode', 'nomination')
            ->where('status', VotingSubject::STATUS_CLOSED)
            ->exists();
    }

    public function broadcastOn(): array
    {
        // Existing owner subscriptions remain connected long enough to receive this close notice.
        // New subscriptions to closed subjects are still rejected by channel authorization.
        return $this->broadcastWhen()
            ? [new PrivateChannel("events.{$this->eventId}.nominations.{$this->subjectId}")]
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
