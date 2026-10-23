<?php

use App\Models\Event;
use App\Models\User;
use App\Models\VotingSubject;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('events.{eventId}.attendees', function (User $user, string $eventId): bool {
    return $user->isAdmin() && Event::query()
        ->whereKey($eventId)
        ->where('created_by', $user->id)
        ->exists();
});

Broadcast::channel('events.{eventId}.voting.{subjectId}', function (User $user, string $eventId, string $subjectId): bool {
    return $user->isAdmin() && VotingSubject::query()
        ->whereKey($subjectId)
        ->where('event_id', $eventId)
        ->where('mode', 'manual')
        ->where('status', VotingSubject::STATUS_ACTIVE)
        ->whereHas('event', fn ($query) => $query->where('created_by', $user->id))
        ->exists();
});

Broadcast::channel('events.{eventId}.nominations.{subjectId}', function (User $user, string $eventId, string $subjectId): bool {
    return $user->isAdmin() && VotingSubject::whereKey($subjectId)
        ->where('event_id', $eventId)
        ->where('mode', 'nomination')
        ->where('status', VotingSubject::STATUS_ACTIVE)
        ->whereHas('event', fn ($query) => $query->where('created_by', $user->id))
        ->exists();
});
