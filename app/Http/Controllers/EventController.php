<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreEventRequest;
use App\Mail\EventInvitation;
use App\Models\EmailInvitation;
use App\Models\Event;
use App\Models\Registration;
use App\Models\RegistrationForm;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\PngWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class EventController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $now = now();

        $events = Event::query()
            ->where('created_by', $request->user()->id)
            ->where('ends_at', '>=', $now)
            ->with([
                'activeRegistrationForm.fields.options',
            ])
            ->withCount([
                'registrations',
                'acceptedCheckIns',
            ])
            ->orderBy('starts_at', 'asc')
            ->get()
            ->map(function ($event) use ($now) {
                $event->event_state =
                    $event->starts_at <= $now && $event->ends_at >= $now
                        ? 'ongoing'
                        : 'upcoming';

                return $event;
            });

        return response()->json([
            'data' => $events,
        ]);
    }

    public function scannerEvents(Request $request): JsonResponse
    {
        $now = now();
        $events = Event::query()
            ->where('status', 'published')
            ->where('starts_at', '<=', $now->copy()->addHours(48))
            ->where('ends_at', '>=', $now)
            ->when(
                $request->user()->isAdmin(),
                fn ($query) => $query->where('created_by', $request->user()->id),
            )
            ->select(['id', 'slug', 'title', 'venue', 'starts_at', 'ends_at', 'timezone', 'status'])
            ->withCount(['registrations', 'acceptedCheckIns'])
            ->orderBy('starts_at')
            ->get()
            ->filter(fn (Event $event): bool => $this->isScannerEventAvailable($event, $now))
            ->values();

        return response()->json(['data' => $events]);
    }

    public function store(StoreEventRequest $request): JsonResponse
    {
        $event = DB::transaction(function () use ($request): Event {
            $data = $this->normalizedEventData($request);
            $data['created_by'] = $request->user()->id;
            $data['slug'] = $this->uniqueHashedSlug();

            $event = Event::create($data);
            $form = $event->registrationForms()->create([
                'version' => 1,
                'title' => $event->title.' Registration',
                'description' => $event->description,
                'is_active' => true,
                'published_at' => $event->status === 'draft' ? null : now(),
            ]);

            foreach ($request->validated('registration_form') as $position => $fieldData) {
                $options = $fieldData['options'];
                unset($fieldData['options'], $fieldData['required']);

                $field = $form->fields()->create([
                    ...$fieldData,
                    'is_required' => $request->input("registration_form.$position.required"),
                    'position' => $position,
                ]);

                foreach ($options as $optionPosition => $option) {
                    $field->options()->create([
                        'label' => $option,
                        'value' => (Str::slug($option) ?: 'option').'-'.($optionPosition + 1),
                        'position' => $optionPosition,
                    ]);
                }
            }

            return $event;
        });

        return response()->json([
            'data' => $this->loadEvent($event),
            'message' => 'Event published successfully.',
        ], 201);
    }

    public function show(Request $request, Event $event): JsonResponse
    {
        $this->ensureOwner($request, $event);

        return response()->json(['data' => $this->loadEvent($event)]);
    }

    public function update(StoreEventRequest $request, Event $event): JsonResponse
    {
        $this->ensureOwner($request, $event);

        DB::transaction(function () use ($request, $event): void {
            $event->update($this->normalizedEventData($request));
            $event->registrationForms()->update(['is_active' => false]);

            $form = $event->registrationForms()->create([
                'version' => ((int) $event->registrationForms()->max('version')) + 1,
                'title' => $event->title.' Registration',
                'description' => $event->description,
                'is_active' => true,
                'published_at' => $event->status === 'draft' ? null : now(),
            ]);

            $this->createFormFields($form, $request->validated('registration_form'));
        });

        return response()->json([
            'data' => $this->loadEvent($event->refresh()),
            'message' => 'Event updated successfully.',
        ]);
    }

    public function updateRegistrationAvailability(Request $request, Event $event): JsonResponse
    {
        $this->ensureOwner($request, $event);
        $validated = $request->validate([
            'is_open' => ['required', 'boolean'],
        ]);

        $event->update(['registration_is_open' => $validated['is_open']]);

        return response()->json([
            'data' => $this->loadEvent($event->refresh()),
            'message' => $event->registration_is_open
                ? 'Event registration is now open.'
                : 'Event registration is now closed.',
        ]);
    }

    public function registrations(Request $request, Event $event): JsonResponse
    {
        $this->ensureOwner($request, $event);

        $filters = $request->validate([
            'search' => ['sometimes', 'string', 'max:120'],
            'status' => ['sometimes', Rule::in(['confirmed', 'cancelled', 'rejected'])],
            'check_in' => ['sometimes', Rule::in(['checked_in', 'not_checked_in'])],
            'sort_by' => ['sometimes', Rule::in(['registered_at', 'name', 'status'])],
            'sort_direction' => ['sometimes', Rule::in(['asc', 'desc'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $query = $event->registrations()
            ->with(['attendee', 'answers.field', 'checkIns'])
            ->when(isset($filters['status']), fn ($query) => $query->where('registrations.status', $filters['status']))
            ->when(isset($filters['check_in']), function ($query) use ($filters): void {
                $acceptedCheckIn = fn ($checkIns) => $checkIns->where('result', 'accepted');

                if ($filters['check_in'] === 'checked_in') {
                    $query->whereHas('checkIns', $acceptedCheckIn);
                } else {
                    $query->whereDoesntHave('checkIns', $acceptedCheckIn);
                }
            })
            ->when(! empty($filters['search']), function ($query) use ($filters): void {
                $terms = preg_split('/\s+/', trim($filters['search']), -1, PREG_SPLIT_NO_EMPTY);

                foreach ($terms as $term) {
                    $query->where(function ($query) use ($term): void {
                        $query->where('registrations.registration_code', 'like', "%{$term}%")
                            ->orWhereHas('attendee', function ($attendeeQuery) use ($term): void {
                                $attendeeQuery
                                    ->where('first_name', 'like', "%{$term}%")
                                    ->orWhere('last_name', 'like', "%{$term}%")
                                    ->orWhere('email', 'like', "%{$term}%");
                            });
                    });
                }
            });

        $sortBy = $filters['sort_by'] ?? 'registered_at';
        $sortDirection = $filters['sort_direction'] ?? 'desc';

        if ($sortBy === 'name') {
            $query->leftJoin('attendees as registration_attendees', 'registration_attendees.id', '=', 'registrations.attendee_id')
                ->select('registrations.*')
                ->orderBy('registration_attendees.first_name', $sortDirection)
                ->orderBy('registration_attendees.last_name', $sortDirection);
        } else {
            $query->orderBy("registrations.{$sortBy}", $sortDirection);
        }

        $registrations = $query
            ->orderBy('registrations.id', $sortDirection)
            ->paginate((int) ($filters['per_page'] ?? 50));

        return response()->json([
            ...$registrations->toArray(),
            'all_total' => $event->registrations()->count(),
        ]);
    }

    public function registrationExport(Request $request, Event $event): JsonResponse
    {
        $this->ensureOwner($request, $event);

        $registrations = $event->registrations()
            ->with(['attendee', 'answers.field', 'checkIns'])
            ->latest('registered_at')
            ->get();

        return response()->json(['data' => $registrations]);
    }

    public function sendInvitation(Request $request, Event $event): JsonResponse
    {
        $this->ensureOwner($request, $event);

        $emailInput = $request->input('emails', []);

        if (is_array($emailInput)) {
            $request->merge([
                'emails' => collect($emailInput)
                    ->map(fn ($email) => is_string($email) ? strtolower(trim($email)) : $email)
                    ->unique()
                    ->values()
                    ->all(),
            ]);
        }

        $validated = $request->validate([
            'emails' => ['required', 'array', 'min:1', 'max:100'],
            'emails.*' => ['required', 'string', 'email:rfc', 'max:255'],
        ]);
        $registrationUrl = rtrim((string) config('app.frontend_url'), '/').'/register/'.$event->slug;
        $invitations = collect();
        $qrPng = Builder::create()
            ->writer(new PngWriter)
            ->data($registrationUrl)
            ->encoding(new Encoding('UTF-8'))
            ->errorCorrectionLevel(ErrorCorrectionLevel::Medium)
            ->size(300)
            ->margin(12)
            ->roundBlockSizeMode(RoundBlockSizeMode::Margin)
            ->build()
            ->getString();

        foreach ($validated['emails'] as $email) {
            $invitation = $event->emailInvitations()->create([
                'email' => $email,
                'status' => 'failed',
            ]);

            try {
                Mail::to($email)->send(new EventInvitation($event, $registrationUrl, $qrPng));

                $invitation->update(['status' => 'sent']);
            } catch (\Throwable) {
                // The audit log intentionally stores only the delivery status.
            }

            $invitations->push($invitation->refresh());
        }

        $sentCount = $invitations->where('status', 'sent')->count();
        $failedCount = $invitations->where('status', 'failed')->count();

        return response()->json([
            'data' => $invitations->map(fn (EmailInvitation $invitation) => $this->invitationData($event, $invitation))->values(),
            'message' => $failedCount === 0
                ? ($sentCount === 1 ? 'Invitation sent successfully.' : "{$sentCount} invitations sent successfully.")
                : "{$sentCount} sent, {$failedCount} failed.",
            'sent_count' => $sentCount,
            'failed_count' => $failedCount,
            'registration_url' => $registrationUrl,
        ]);
    }

    public function invitations(Request $request, Event $event): JsonResponse
    {
        $this->ensureOwner($request, $event);

        $invitations = $event->emailInvitations()
            ->latest('id')
            ->limit(20)
            ->get()
            ->map(fn (EmailInvitation $invitation) => $this->invitationData($event, $invitation));

        return response()->json(['data' => $invitations]);
    }

    public function checkIn(Request $request, Event $event): JsonResponse
    {
        if ($request->user()->isAdmin()) {
            $this->ensureOwner($request, $event);
        } else {
            abort_unless($this->isScannerEventAvailable($event), 404);
        }
        $validated = $request->validate([
            'registration_code' => ['required', 'string'],
            'gate' => ['nullable', 'string', 'max:100'],
            'device_id' => ['nullable', 'string', 'max:100'],
        ]);

        return DB::transaction(function () use ($event, $request, $validated): JsonResponse {
            $registration = Registration::query()
                ->where('event_id', $event->id)
                ->where('registration_code', $validated['registration_code'])
                ->where('status', 'confirmed')
                ->lockForUpdate()
                ->firstOrFail();

            $duplicate = $registration->checkIns()->where('result', 'accepted')->exists();
            $checkIn = $registration->checkIns()->create([
                'checked_in_by' => $request->user()->id,
                'checked_in_at' => now(),
                'gate' => $validated['gate'] ?? null,
                'device_id' => $validated['device_id'] ?? null,
                'result' => $duplicate ? 'duplicate' : 'accepted',
            ]);

            return response()->json([
                'data' => $checkIn->load('registration.attendee'),
                'message' => $duplicate ? 'Attendee was already checked in.' : 'Check-in accepted.',
            ], $duplicate ? 409 : 201);
        });
    }

    private function loadEvent(Event $event): Event
    {
        return $event->load(['activeRegistrationForm.fields.options'])
            ->loadCount(['registrations', 'acceptedCheckIns']);
    }

    private function createFormFields(RegistrationForm $form, array $fields): void
    {
        foreach ($fields as $position => $fieldData) {
            $options = $fieldData['options'];
            $required = $fieldData['required'];
            unset($fieldData['options'], $fieldData['required']);

            $field = $form->fields()->create([
                ...$fieldData,
                'is_required' => $required,
                'position' => $position,
            ]);

            foreach ($options as $optionPosition => $option) {
                $field->options()->create([
                    'label' => $option,
                    'value' => (Str::slug($option) ?: 'option').'-'.($optionPosition + 1),
                    'position' => $optionPosition,
                ]);
            }
        }
    }

    private function ensureOwner(Request $request, Event $event): void
    {
        abort_unless($event->created_by === $request->user()->id, 404);
    }

    private function isScannerEventAvailable(Event $event, ?Carbon $at = null): bool
    {
        if ($event->status !== 'published') {
            return false;
        }

        $at ??= now();
        $availableFrom = $event->starts_at
            ->copy()
            ->setTimezone($event->timezone)
            ->startOfDay()
            ->utc();

        return $at->greaterThanOrEqualTo($availableFrom)
            && $at->lessThanOrEqualTo($event->ends_at);
    }

    private function normalizedEventData(StoreEventRequest $request): array
    {
        $data = $request->safe()->except('registration_form');

        foreach (['starts_at', 'ends_at', 'registration_opens_at', 'registration_closes_at'] as $field) {
            if (! empty($data[$field])) {
                $data[$field] = Carbon::parse($data[$field])->utc()->format('Y-m-d H:i:s');
            }
        }

        return $data;
    }

    private function invitationData(Event $event, EmailInvitation $invitation): array
    {
        return [
            'id' => $invitation->id,
            'email' => $invitation->email,
            'status' => $invitation->status,
            'created_at' => $invitation->created_at,
            'event' => [
                'slug' => $event->slug,
                'title' => $event->title,
            ],
        ];
    }

    private function uniqueHashedSlug(): string
    {
        do {
            $slug = hash('sha256', Str::random(64));
        } while (Event::withTrashed()->where('slug', $slug)->exists());

        return $slug;
    }
}
