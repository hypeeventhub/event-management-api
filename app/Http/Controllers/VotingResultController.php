<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\VotingContestant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class VotingResultController extends Controller
{
    public function show(Request $request, Event $event, string $subject): JsonResponse
    {
        abort_unless($event->created_by === $request->user()->id, 404);

        $record = $event->votingSubjects()->where('slug', $subject)->firstOrFail();
        $totalRegistrations = $event->registrations()->where('status', 'confirmed')->count();
        $voteCounts = DB::table('voting_votes')
            ->select('voting_contestant_id')
            ->selectRaw('COUNT(*) as votes_count')
            ->where('voting_subject_id', $record->id)
            ->groupBy('voting_contestant_id');
        $rows = $record->contestants()
            ->select(['voting_contestants.id', 'voting_contestants.name', 'voting_contestants.display_order'])
            ->leftJoinSub($voteCounts, 'vote_counts', 'voting_contestants.id', '=', 'vote_counts.voting_contestant_id')
            ->selectRaw('COALESCE(vote_counts.votes_count, 0) as votes_count')
            ->orderByDesc('votes_count')
            ->orderBy('voting_contestants.display_order')
            ->orderBy('voting_contestants.id')
            ->get();
        // The denominator comes from the same query snapshot as every row.
        $totalVotes = (int) $rows->sum('votes_count');
        $contestants = $rows
            ->map(fn (VotingContestant $contestant): array => [
                'id' => $contestant->id,
                'name' => $contestant->name,
                'display_order' => $contestant->display_order,
                'votes' => (int) $contestant->votes_count,
                'percentage' => $totalVotes === 0 ? 0 : round($contestant->votes_count * 100 / $totalVotes, 2),
            ]);

        return response()->json([
            'event' => $event->only(['id', 'slug', 'title']),
            'subject' => $record->only(['id', 'slug', 'title', 'status']),
            'total_votes' => $totalVotes,
            'total_registrations' => $totalRegistrations,
            'participation_percentage' => $totalRegistrations === 0 ? 0 : round($totalVotes * 100 / $totalRegistrations, 2),
            'contestants' => $contestants,
        ]);
    }
}
