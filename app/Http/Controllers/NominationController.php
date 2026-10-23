<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreNominationRequest;
use App\Http\Requests\UpdateNominationRequest;
use App\Http\Resources\VotingSubjectResource;
use App\Models\Event;
use App\Models\VotingSubject;
use App\Services\NominationService;
use App\Services\NomineeSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NominationController extends Controller
{
    public function store(StoreNominationRequest $request, Event $event, NominationService $nominations): JsonResponse
    {
        $subject = $nominations->store($event, $request->validated());

        return response()->json(['data' => (new VotingSubjectResource($subject))->resolve($request)], 201);
    }

    public function update(UpdateNominationRequest $request, Event $event, VotingSubject $subject, NominationService $nominations): JsonResponse
    {
        $record = $nominations->update($event, $subject, $request->validated());

        return response()->json(['data' => (new VotingSubjectResource($record))->resolve($request)]);
    }

    public function open(Request $request, Event $event, VotingSubject $subject, NominationService $nominations): JsonResponse
    {
        abort_unless($event->created_by === $request->user()->id, 404);
        $record = $nominations->open($event, $subject);

        return response()->json(['data' => (new VotingSubjectResource($record))->resolve($request)]);
    }

    public function close(Request $request, Event $event, VotingSubject $subject, NominationService $nominations): JsonResponse
    {
        abort_unless($event->created_by === $request->user()->id, 404);
        $record = $nominations->close($event, $subject);

        return response()->json(['data' => (new VotingSubjectResource($record))->resolve($request)]);
    }

    public function candidates(Request $request, Event $event, NomineeSearchService $search): JsonResponse
    {
        abort_unless($event->created_by === $request->user()->id, 404);

        $data = $request->validate(['q' => ['nullable', 'string', 'max:511']]);

        return response()->json(['data' => $search->search($event, $data['q'] ?? '')]);
    }
}
