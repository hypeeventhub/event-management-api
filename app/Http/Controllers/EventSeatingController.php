<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\EventSeatAssignment;
use App\Models\Registration;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EventSeatingController extends Controller
{
    public function show(Request $request, Event $event): JsonResponse
    {
        $this->ensureOwner($request, $event);

        $assignments = $event->seatAssignments()
            ->with('registration.attendee')
            ->orderBy('table_number')
            ->orderBy('seat_number')
            ->get()
            ->map(fn (EventSeatAssignment $assignment): array => $this->assignmentData($assignment));
        $confirmedRegistrationCount = $event->registrations()->where('status', 'confirmed')->count();
        $confirmedAssignedCount = $event->seatAssignments()
            ->whereHas('registration', fn ($query) => $query->where('status', 'confirmed'))
            ->count();

        return response()->json([
            'data' => [
                'enabled' => $event->seating_enabled,
                'table_count' => $event->table_count,
                'seats_per_table' => $event->seats_per_table,
                'total_seats' => $event->seating_enabled ? $event->table_count * $event->seats_per_table : 0,
                'confirmed_registrations_count' => $confirmedRegistrationCount,
                'assigned_count' => $assignments->count(),
                'confirmed_assigned_count' => $confirmedAssignedCount,
                'unassigned_confirmed_count' => max(0, $confirmedRegistrationCount - $confirmedAssignedCount),
                'assignments' => $assignments->values(),
            ],
        ]);
    }

    public function assign(Request $request, Event $event, int $registrationId): JsonResponse
    {
        $this->ensureOwner($request, $event);
        abort_unless($event->seating_enabled, 409, 'Seating is not enabled for this event.');

        $validated = $request->validate([
            'table_number' => ['required', 'integer', 'min:1', 'max:'.$event->table_count],
            'seat_number' => ['required', 'integer', 'min:1', 'max:'.$event->seats_per_table],
        ]);

        $registration = $event->registrations()
            ->where('status', 'confirmed')
            ->whereKey($registrationId)
            ->firstOrFail();

        try {
            $assignment = DB::transaction(function () use ($event, $registration, $request, $validated): EventSeatAssignment {
                $registration = Registration::query()->whereKey($registration->id)->lockForUpdate()->firstOrFail();
                $assignment = $event->seatAssignments()->firstOrNew(['registration_id' => $registration->id]);
                $assignment->fill([
                    ...$validated,
                    'assigned_by' => $request->user()->id,
                ]);
                $assignment->save();

                return $assignment->load('registration.attendee');
            });
        } catch (UniqueConstraintViolationException) {
            return response()->json(['message' => 'That seat is already assigned. Choose another seat.'], 409);
        }

        return response()->json([
            'data' => $this->assignmentData($assignment),
            'message' => 'Seat assigned successfully.',
        ]);
    }

    public function unassign(Request $request, Event $event, int $registrationId): JsonResponse
    {
        $this->ensureOwner($request, $event);

        $event->seatAssignments()
            ->where('registration_id', $registrationId)
            ->delete();

        return response()->json(['message' => 'Seat assignment removed.']);
    }

    private function ensureOwner(Request $request, Event $event): void
    {
        abort_unless($event->created_by === $request->user()->id, 404);
    }

    private function assignmentData(EventSeatAssignment $assignment): array
    {
        return [
            'id' => $assignment->id,
            'registration_id' => $assignment->registration_id,
            'first_name' => $assignment->registration?->attendee?->first_name,
            'last_name' => $assignment->registration?->attendee?->last_name,
            'table_number' => $assignment->table_number,
            'seat_number' => $assignment->seat_number,
        ];
    }
}
