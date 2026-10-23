<?php

namespace App\Services;

use App\Models\VotingSubject;
use Illuminate\Support\Facades\DB;

class VotingStatistics
{
    /**
     * Return the compact private transport payload for a live vote or nomination update.
     * Names and presentation metadata stay in the authorized REST response; the
     * positional vote array is ordered by display_order then id and stays small
     * enough for Reverb's default message limit.
     */
    public function forLiveBroadcast(VotingSubject $subject): array
    {
        $subject->loadMissing('event');
        $voteCounts = DB::table('voting_votes')
            ->select('voting_contestant_id')
            ->selectRaw('COUNT(*) as votes_count')
            ->where('voting_subject_id', $subject->id)
            ->groupBy('voting_contestant_id');
        $rows = $subject->contestants()
            ->leftJoinSub($voteCounts, 'vote_counts', 'voting_contestants.id', '=', 'vote_counts.voting_contestant_id')
            ->selectRaw('COALESCE(vote_counts.votes_count, 0) as votes_count')
            ->orderBy('voting_contestants.display_order')
            ->orderBy('voting_contestants.id')
            ->get(['voting_contestants.id']);

        $contestantVotes = $rows->map(fn ($row) => (int) $row->votes_count)->all();

        return [
            'event_id' => (int) $subject->event_id,
            'subject_id' => (int) $subject->id,
            'status' => $subject->status,
            'total_votes' => array_sum($contestantVotes),
            'total_registrations' => $subject->event->registrations()->where('status', 'confirmed')->count(),
            'contestant_votes' => $contestantVotes,
        ];
    }

    /** @deprecated Use forLiveBroadcast() for both manual voting and nominations. */
    public function forNominationBroadcast(VotingSubject $subject): array
    {
        return $this->forLiveBroadcast($subject);
    }

    public function forSubject(VotingSubject $subject): array
    {
        $event = $subject->event;
        $totalRegistrations = $event->registrations()->where('status', 'confirmed')->count();
        $voteCounts = DB::table('voting_votes')
            ->select('voting_contestant_id')
            ->selectRaw('COUNT(*) as votes_count')
            ->where('voting_subject_id', $subject->id)
            ->groupBy('voting_contestant_id');
        $rows = $subject->contestants()
            ->select(['voting_contestants.id', 'voting_contestants.name', 'voting_contestants.display_order'])
            ->leftJoinSub($voteCounts, 'vote_counts', 'voting_contestants.id', '=', 'vote_counts.voting_contestant_id')
            ->selectRaw('COALESCE(vote_counts.votes_count, 0) as votes_count')
            ->orderByDesc('votes_count')
            ->orderBy('voting_contestants.display_order')
            ->orderBy('voting_contestants.id')
            ->get();
        // Totals, ranks and shares all use the same vote-count snapshot.
        $totalVotes = (int) $rows->sum('votes_count');
        $previousVotes = null;
        $rank = 0;
        $contestants = [];
        foreach ($rows as $index => $contestant) {
            $votes = (int) $contestant->votes_count;
            if ($votes !== $previousVotes) {
                $rank = $index + 1;
            }
            $previousVotes = $votes;
            $contestants[] = [
                'id' => $contestant->id,
                'name' => $contestant->name,
                'display_order' => $contestant->display_order,
                'votes' => $votes,
                'rank' => $rank,
                'percentage' => $totalVotes === 0 ? 0 : round($votes * 100 / $totalVotes, 2),
            ];
        }

        return [
            'event' => $event->only(['id', 'slug', 'title']),
            'subject' => $subject->only(['id', 'slug', 'title', 'status']),
            'total_votes' => $totalVotes,
            'total_registrations' => $totalRegistrations,
            'participation_percentage' => $totalRegistrations === 0 ? 0 : round($totalVotes * 100 / $totalRegistrations, 2),
            'contestants' => $contestants,
        ];
    }
}
