<?php

namespace App\Http\Controllers;

use App\Http\Requests\SubmitVoteRequest;
use App\Models\VotingSubject;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PublicVotingController extends Controller
{
    public function show(VotingSubject $subject): JsonResponse
    {
        if ($subject->status !== VotingSubject::STATUS_ACTIVE) {
            return $this->notFound();
        }

        $contestants = $subject->contestants()->orderBy('display_order')->orderBy('id')
            ->get(['id', 'name'])
            ->map(fn ($contestant): array => $contestant->only(['id', 'name']));

        return response()->json([
            'title' => $subject->title,
            'contestants' => $contestants,
        ]);
    }

    public function store(SubmitVoteRequest $request, VotingSubject $subject): JsonResponse
    {
        $validated = $request->validated();
        $code = Str::upper(trim($validated['registration_code']));

        try {
            return DB::transaction(function () use ($subject, $validated, $code): JsonResponse {
                $lockedSubject = VotingSubject::query()->whereKey($subject->id)->lockForUpdate()->first();
                if (! $lockedSubject || $lockedSubject->status !== VotingSubject::STATUS_ACTIVE) {
                    return $this->notFound();
                }

                $contestant = $lockedSubject->contestants()->whereKey($validated['contestant_id'])->first();
                if (! $contestant) {
                    return response()->json(['message' => 'Invalid contestant.'], 422);
                }

                $registration = $lockedSubject->event->registrations()
                    ->where('registration_code', $code)
                    ->where('status', 'confirmed')
                    ->lockForUpdate()
                    ->first();
                if (! $registration) {
                    return response()->json(['message' => 'Invalid registration code.'], 422);
                }

                $lockedSubject->votes()->create([
                    'voting_contestant_id' => $contestant->id,
                    'registration_id' => $registration->id,
                ]);

                return response()->json(['message' => 'Your vote has been recorded.'], 201);
            });
        } catch (UniqueConstraintViolationException $exception) {
            if (! $this->isDuplicateVoteConstraint($exception)) {
                throw $exception;
            }

            return response()->json(['message' => 'You have already voted for this subject.'], 409);
        }
    }

    private function notFound(): JsonResponse
    {
        return response()->json(['message' => 'Not Found'], 404);
    }

    private function isDuplicateVoteConstraint(UniqueConstraintViolationException $exception): bool
    {
        $message = $exception->getMessage();

        return str_contains($message, 'voting_votes_voting_subject_id_registration_id_unique')
            || str_contains($message, 'voting_votes.voting_subject_id, voting_votes.registration_id');
    }
}
