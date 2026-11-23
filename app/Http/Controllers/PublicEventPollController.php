<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\NominationTopic;
use App\Models\VotingSubject;
use Illuminate\Http\JsonResponse;

class PublicEventPollController extends Controller
{
    public function show(Event $event): JsonResponse
    {
        $currentTopic = $this->currentTopic($event);
        $topic = $currentTopic ? $this->activeTopicData($currentTopic) : null;
        if ($currentTopic && $topic === null) {
            // A transition may have committed between reading the stage and its rounds.
            $latestTopic = $this->currentTopic($event);
            if ($latestTopic && ($latestTopic->id !== $currentTopic->id || $latestTopic->stage !== $currentTopic->stage)) {
                $topic = $this->activeTopicData($latestTopic);
            }
            $currentTopic = $latestTopic;
        }

        $standaloneSubjects = $event->votingSubjects()->whereNull('nomination_topic_id');
        $activeSubjects = (clone $standaloneSubjects)->where('status', VotingSubject::STATUS_ACTIVE)
            ->orderBy('id')
            ->get(['mode', 'slug', 'title'])
            ->groupBy('mode');

        $publicSubjects = fn (string $mode): array => $activeSubjects->get($mode, collect())
            ->map(fn (VotingSubject $subject): array => $subject->only(['slug', 'title']))
            ->values()
            ->all();
        $manualBallots = $publicSubjects('manual');
        $standaloneNominations = $publicSubjects('nomination');

        $stage = match (true) {
            $topic !== null => $currentTopic->stage,
            $manualBallots !== [], $standaloneNominations !== [] => 'open',
            $currentTopic !== null => 'closed',
            $event->nominationTopics()->where('stage', NominationTopic::STAGE_DRAFT)->exists(),
            (clone $standaloneSubjects)->where('status', VotingSubject::STATUS_DRAFT)->exists() => 'waiting',
            $event->nominationTopics()->exists(), $standaloneSubjects->exists() => 'closed',
            default => 'empty',
        };

        return response()->json(['data' => [
            'title' => $event->title,
            'stage' => $stage,
            'topic' => $topic,
            'manual_ballots' => $manualBallots,
            'standalone_nominations' => $standaloneNominations,
        ]])->header('Cache-Control', 'no-store');
    }

    private function currentTopic(Event $event): ?NominationTopic
    {
        return $event->nominationTopics()
            ->whereIn('stage', [NominationTopic::STAGE_NOMINATION_ACTIVE, NominationTopic::STAGE_VOTING_ACTIVE])
            ->orderBy('position')
            ->first(['id', 'title', 'stage']);
    }

    private function activeTopicData(NominationTopic $topic): ?array
    {
        $rounds = $topic->stage === NominationTopic::STAGE_NOMINATION_ACTIVE
            ? $topic->nominationRounds()
            : $topic->votingRounds();
        $slugs = $rounds->where('status', VotingSubject::STATUS_ACTIVE)
            ->whereIn('category', NominationTopic::CATEGORIES)
            ->pluck('slug', 'category');

        // The paired endpoint requires both rounds to still be active.
        if (! $slugs->has(NominationTopic::CATEGORIES)) {
            return null;
        }

        return [
            'id' => $topic->id,
            'title' => $topic->title,
            'subject_slugs' => $slugs->all(),
        ];
    }
}
