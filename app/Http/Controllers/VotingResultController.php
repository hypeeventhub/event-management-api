<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Services\VotingStatistics;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VotingResultController extends Controller
{
    public function show(Request $request, Event $event, string $subject, VotingStatistics $statistics): JsonResponse
    {
        abort_unless($event->created_by === $request->user()->id, 404);

        $record = $event->votingSubjects()->where('slug', $subject)->firstOrFail();

        return response()->json($statistics->forSubject($record));
    }
}
