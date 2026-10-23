<?php

namespace App\Events;

use App\Models\VotingSubject;
use App\Services\VotingStatistics;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

class VotingStatisticsUpdated implements ShouldBroadcast, ShouldDispatchAfterCommit, ShouldRescue
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
            ->where('status', VotingSubject::STATUS_ACTIVE)
            ->exists();
    }

    public function broadcastOn(): array
    {
        return $this->broadcastWhen()
            ? [new PrivateChannel("events.{$this->eventId}.voting.{$this->subjectId}")]
            : [];
    }

    public function broadcastWith(): array
    {
        return app(VotingStatistics::class)->forLiveBroadcast(VotingSubject::findOrFail($this->subjectId));
    }
}
