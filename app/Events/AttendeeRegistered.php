<?php

namespace App\Events;

use App\Models\Registration;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

class AttendeeRegistered implements ShouldBroadcast, ShouldDispatchAfterCommit, ShouldRescue
{
    private int $registrationId;

    private int $eventId;

    public function __construct(Registration $registration)
    {
        $this->registrationId = $registration->id;
        $this->eventId = $registration->event_id;
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel("events.{$this->eventId}.attendees")];
    }

    public function broadcastAs(): string
    {
        return 'attendee.registered';
    }

    public function broadcastWith(): array
    {
        // The event is only a signal to refresh the owner's paginated list; attendee PII stays in the API.
        return ['registration_id' => $this->registrationId];
    }
}
