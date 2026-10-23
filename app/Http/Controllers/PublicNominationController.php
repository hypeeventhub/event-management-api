<?php

namespace App\Http\Controllers;

use App\Events\NominationStatisticsUpdated;
use App\Http\Requests\SubmitNominationRequest;
use App\Models\Attendee;
use App\Models\VotingSubject;
use App\Services\NomineeSearchService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PublicNominationController extends Controller
{
    public function show(VotingSubject $subject): JsonResponse
    {
        abort_unless($subject->mode === 'nomination', 404);

        return response()->json(['data' => [
            'title' => $subject->title,
            'status' => $subject->status,
        ]]);
    }

    public function candidates(Request $request, VotingSubject $subject, NomineeSearchService $search): JsonResponse
    {
        $this->ensureOpenNomination($subject);
        $data = $request->validate(['q' => ['nullable', 'string', 'max:511']]);

        return response()->json(['data' => $search->search($subject->event, $data['q'] ?? '')]);
    }

    public function submit(SubmitNominationRequest $request, VotingSubject $subject): JsonResponse
    {
        $validated = $request->validated();
        $registrationCode = Str::upper(trim($validated['registration_code']));

        try {
            return DB::transaction(function () use ($subject, $validated, $registrationCode): JsonResponse {
                // Serialize nominations on this subject so concurrent voters
                // cannot create duplicate contestants for the same attendee.
                $lockedSubject = VotingSubject::query()->whereKey($subject->id)->lockForUpdate()->first();
                if (! $lockedSubject
                    || $lockedSubject->mode !== 'nomination'
                    || $lockedSubject->status !== VotingSubject::STATUS_ACTIVE) {
                    return $this->notFound();
                }

                $event = $lockedSubject->event;
                $registration = $event->registrations()
                    ->where('registration_code', $registrationCode)
                    ->where('status', 'confirmed')
                    ->lockForUpdate()
                    ->first();
                if (! $registration) {
                    return response()->json(['message' => 'Invalid registration code.'], 422);
                }

                // Candidates can have any registration status, but must be
                // associated with this event. Search and submission share the
                // same event scope to prevent arbitrary attendee IDs.
                $attendee = Attendee::query()
                    ->whereKey($validated['attendee_id'])
                    ->whereHas('registrations', fn ($registrations) => $registrations->where('event_id', $event->id))
                    ->lockForUpdate()
                    ->first();
                if (! $attendee) {
                    return response()->json(['message' => 'Invalid nominee.'], 422);
                }

                $contestant = $lockedSubject->contestants()->where('attendee_id', $attendee->id)->first();
                if (! $contestant) {
                    $contestant = $lockedSubject->contestants()->create([
                        'attendee_id' => $attendee->id,
                        'name' => Str::squish($attendee->first_name.' '.$attendee->last_name),
                        'display_order' => ((int) $lockedSubject->contestants()->max('display_order')) + 1,
                    ]);
                }

                $lockedSubject->votes()->create([
                    'voting_contestant_id' => $contestant->id,
                    'registration_id' => $registration->id,
                ]);

                event(new NominationStatisticsUpdated($lockedSubject));

                return response()->json(['message' => 'Your nomination has been submitted.'], 201);
            });
        } catch (UniqueConstraintViolationException $exception) {
            if (! $this->isDuplicateSubmission($exception)) {
                throw $exception;
            }

            return response()->json(['message' => 'You have already submitted a nomination.'], 409);
        }
    }

    private function ensureOpenNomination(VotingSubject $subject): void
    {
        abort_unless(
            $subject->mode === 'nomination' && $subject->status === VotingSubject::STATUS_ACTIVE,
            404,
        );
    }

    private function notFound(): JsonResponse
    {
        return response()->json(['message' => 'Not Found'], 404);
    }

    private function isDuplicateSubmission(UniqueConstraintViolationException $exception): bool
    {
        $message = $exception->getMessage();

        return str_contains($message, 'voting_votes_voting_subject_id_registration_id_unique')
            || str_contains($message, 'voting_votes.voting_subject_id, voting_votes.registration_id');
    }
}
