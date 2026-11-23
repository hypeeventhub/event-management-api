<?php

namespace App\Http\Controllers;

use App\Events\NominationStatisticsUpdated;
use App\Events\VotingStatisticsUpdated;
use App\Http\Requests\SubmitTopicNominationsRequest;
use App\Http\Requests\SubmitTopicVotesRequest;
use App\Models\Attendee;
use App\Models\Event;
use App\Models\NominationTopic;
use App\Models\VotingSubject;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PublicTopicSubmissionController extends Controller
{
    public function nominations(SubmitTopicNominationsRequest $request, Event $event, NominationTopic $topic): JsonResponse
    {
        $data = $request->validated();

        return $this->submitPair($event, $topic, $data['registration_code'], [
            'female' => (int) $data['female_attendee_id'],
            'male' => (int) $data['male_attendee_id'],
        ], nomination: true);
    }

    public function votes(SubmitTopicVotesRequest $request, Event $event, NominationTopic $topic): JsonResponse
    {
        $data = $request->validated();

        return $this->submitPair($event, $topic, $data['registration_code'], [
            'female' => (int) $data['female_contestant_id'],
            'male' => (int) $data['male_contestant_id'],
        ], nomination: false);
    }

    private function submitPair(Event $event, NominationTopic $topic, string $code, array $choices, bool $nomination): JsonResponse
    {
        $code = Str::upper(trim($code));
        $duplicateMessage = $nomination
            ? 'You have already submitted nominations for this topic.'
            : 'You have already voted for this topic.';

        try {
            DB::transaction(function () use ($event, $topic, $code, $choices, $nomination, $duplicateMessage): void {
                $record = $event->nominationTopics()->whereKey($topic->id)->lockForUpdate()->first();
                $stage = $nomination ? NominationTopic::STAGE_NOMINATION_ACTIVE : NominationTopic::STAGE_VOTING_ACTIVE;
                abort_unless($record?->stage === $stage, response()->json(['message' => 'Not Found'], 404));

                $rounds = ($nomination ? $record->nominationRounds() : $record->votingRounds())
                    ->where('status', VotingSubject::STATUS_ACTIVE)
                    ->whereIn('category', NominationTopic::CATEGORIES)
                    ->orderBy('id')->lockForUpdate()->get()->keyBy('category');
                abort_unless($rounds->has(NominationTopic::CATEGORIES), response()->json(['message' => 'Not Found'], 404));

                $registration = $event->registrations()
                    ->where('registration_code', $code)->where('status', 'confirmed')->lockForUpdate()->first();
                abort_unless($registration, response()->json(['message' => 'Invalid registration code.'], 422));

                $checkInMessage = $nomination
                    ? 'You must check in before submitting nominations.'
                    : 'You must check in before voting.';
                abort_unless(
                    $registration->checkIns()->where('result', 'accepted')->exists(),
                    response()->json(['message' => $checkInMessage], 403),
                );
                abort_if(
                    $registration->votingVotes()->whereIn('voting_subject_id', $rounds->modelKeys())->exists(),
                    response()->json(['message' => $duplicateMessage], 409),
                );

                $contestants = $nomination
                    ? $this->nomineeContestants($event, $rounds, $choices)
                    : $this->ballotContestants($rounds, $choices);

                foreach ($rounds as $category => $round) {
                    $round->votes()->create([
                        'voting_contestant_id' => $contestants[$category]->id,
                        'registration_id' => $registration->id,
                    ]);
                }

                foreach ($rounds as $round) {
                    event($nomination ? new NominationStatisticsUpdated($round) : new VotingStatisticsUpdated($round));
                }
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            $message = $exception->getMessage();
            if (! str_contains($message, 'voting_votes_voting_subject_id_registration_id_unique')
                && ! str_contains($message, 'voting_votes.voting_subject_id, voting_votes.registration_id')) {
                throw $exception;
            }

            return response()->json(['message' => $duplicateMessage], 409);
        }

        return response()->json([
            'message' => $nomination ? 'Your nominations have been submitted.' : 'Your votes have been recorded.',
        ], 201);
    }

    private function nomineeContestants(Event $event, Collection $rounds, array $choices): array
    {
        $attendees = Attendee::query()->whereKey(array_values($choices))
            ->whereHas('registrations', fn ($registrations) => $registrations
                ->where('event_id', $event->id)
                ->where('status', 'confirmed')
                ->whereHas('checkIns', fn ($checkIns) => $checkIns->where('result', 'accepted')))
            ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        abort_unless($attendees->has(array_values($choices)), response()->json(['message' => 'Invalid nominee.'], 422));

        $contestants = [];
        foreach ($rounds as $category => $round) {
            $attendee = $attendees[$choices[$category]];
            $contestant = $round->contestants()->where('attendee_id', $attendee->id)->first();
            $contestants[$category] = $contestant ?? $round->contestants()->create([
                'attendee_id' => $attendee->id,
                'name' => Str::squish($attendee->first_name.' '.$attendee->last_name),
                'display_order' => ((int) $round->contestants()->max('display_order')) + 1,
            ]);
        }

        return $contestants;
    }

    private function ballotContestants(Collection $rounds, array $choices): array
    {
        $contestants = [];
        foreach ($rounds as $category => $round) {
            $contestant = $round->contestants()->whereKey($choices[$category])->first();
            abort_unless($contestant, response()->json(['message' => 'Invalid contestant.'], 422));
            $contestants[$category] = $contestant;
        }

        return $contestants;
    }
}
