<?php

namespace App\Http\Controllers;

use App\Models\Attendee;
use App\Models\Registration;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AttendeeDuplicateController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $duplicateAttendeeIds = Registration::query()
            ->select('attendee_id')
            ->where('status', 'confirmed')
            ->groupBy('attendee_id')
            ->havingRaw('COUNT(DISTINCT event_id) >= 2');

        $attendees = Attendee::query()
            ->whereIn('id', $duplicateAttendeeIds)
            ->when(trim($filters['search'] ?? '') !== '', function (Builder $query) use ($filters): void {
                $terms = preg_split('/\s+/', trim($filters['search']), -1, PREG_SPLIT_NO_EMPTY);

                foreach ($terms as $term) {
                    $query->where(function (Builder $query) use ($term): void {
                        $query->where('first_name', 'like', "%{$term}%")
                            ->orWhere('last_name', 'like', "%{$term}%")
                            ->orWhere('email', 'like', "%{$term}%");
                    });
                }
            })
            ->with(['registrations' => function (Relation $query): void {
                $query->where('status', 'confirmed')
                    ->select(['id', 'attendee_id', 'event_id', 'status', 'registered_at'])
                    ->with(['event' => fn (Relation $eventQuery) => $eventQuery
                        ->withTrashed()
                        ->select(['id', 'slug', 'title', 'venue', 'meeting_url', 'starts_at', 'ends_at', 'timezone', 'status', 'deleted_at'])]);
            }])
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->orderBy('id')
            ->paginate((int) ($filters['per_page'] ?? 25));

        return response()->json([
            'data' => $attendees->getCollection()->map(function (Attendee $attendee): array {
                return [
                    'id' => $attendee->id,
                    'first_name' => $attendee->first_name,
                    'last_name' => $attendee->last_name,
                    'email' => $attendee->email,
                    'confirmed_event_count' => $attendee->registrations->count(),
                    'events' => $attendee->registrations
                        ->filter(fn (Registration $registration): bool => $registration->event !== null)
                        ->map(fn (Registration $registration): array => [
                            'slug' => $registration->event->slug,
                            'title' => $registration->event->title,
                            'location' => $registration->event->venue ?: ($registration->event->meeting_url ? 'Online event' : 'Venue pending'),
                            'starts_at' => $registration->event->starts_at,
                            'ends_at' => $registration->event->ends_at,
                            'timezone' => $registration->event->timezone,
                            'event_status' => $registration->event->status,
                            'event_deleted' => $registration->event->trashed(),
                            'registered_at' => $registration->registered_at,
                        ])
                        ->values(),
                ];
            })->values(),
            'meta' => [
                'current_page' => $attendees->currentPage(),
                'last_page' => $attendees->lastPage(),
                'per_page' => $attendees->perPage(),
                'total' => $attendees->total(),
            ],
        ]);
    }
}
