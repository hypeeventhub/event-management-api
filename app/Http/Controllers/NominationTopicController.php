<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreNominationTopicsRequest;
use App\Models\Event;
use App\Models\NominationTopic;
use App\Models\VotingContestant;
use App\Models\VotingVote;
use App\Services\NominationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class NominationTopicController extends Controller
{
    public function index(Request $request, Event $event): JsonResponse
    {
        $this->owner($request, $event);

        return response()->json(['data' => $this->topicData($event)]);
    }

    public function store(StoreNominationTopicsRequest $request, Event $event, NominationService $nominations): JsonResponse
    {
        $topics = $nominations->storeTopics($event, $request->validated('topics'));

        return response()->json(['data' => $this->topicData($event, $topics->modelKeys())], 201);
    }

    public function open(Request $request, Event $event, NominationTopic $topic, NominationService $nominations): JsonResponse
    {
        $this->owner($request, $event);
        $record = $nominations->openTopic($event, $topic);

        return response()->json(['data' => $this->topicData($event, [$record->id])[0] ?? abort(404)]);
    }

    public function closeNominations(Request $request, Event $event, NominationTopic $topic, NominationService $nominations): JsonResponse
    {
        $this->owner($request, $event);
        $record = $nominations->closeTopicNominations($event, $topic);

        return response()->json(['data' => $this->topicData($event, [$record->id])[0] ?? abort(404)]);
    }

    public function closeVoting(Request $request, Event $event, NominationTopic $topic, NominationService $nominations): JsonResponse
    {
        $this->owner($request, $event);
        $record = $nominations->closeTopicVoting($event, $topic);

        return response()->json(['data' => $this->topicData($event, [$record->id])[0] ?? abort(404)]);
    }

    private function owner(Request $request, Event $event): void
    {
        abort_unless($event->created_by === $request->user()->id, 404);
    }

    private function topicData(Event $event, ?array $topicIds = null): array
    {
        // Read stage, round status and counts in one statement, including after a lifecycle change.
        $rows = $event->nominationTopics()
            ->when($topicIds !== null, fn ($query) => $query->whereKey($topicIds))
            ->leftJoin('voting_subjects as rounds', 'rounds.nomination_topic_id', '=', 'nomination_topics.id')
            ->select([
                'nomination_topics.id', 'nomination_topics.event_id', 'nomination_topics.title',
                'nomination_topics.position', 'nomination_topics.female_top_n', 'nomination_topics.male_top_n',
                'nomination_topics.stage', 'rounds.id as round_id', 'rounds.slug', 'rounds.title as round_title',
                'rounds.mode', 'rounds.status', 'rounds.category', 'rounds.generated_from_subject_id',
            ])
            ->addSelect([
                'contestant_count' => VotingContestant::selectRaw('count(*)')->whereColumn('voting_subject_id', 'rounds.id'),
                'total_votes' => VotingVote::selectRaw('count(*)')->whereColumn('voting_subject_id', 'rounds.id'),
            ])
            ->orderBy('nomination_topics.position')->orderBy('rounds.category')->get();

        return $rows->groupBy('id')->map(function (Collection $topicRows): array {
            $topic = $topicRows->first()->only(['id', 'event_id', 'title', 'position', 'female_top_n', 'male_top_n', 'stage']);
            $ballots = $topicRows->whereNotNull('generated_from_subject_id')->keyBy('generated_from_subject_id');
            $nominationRounds = [];
            foreach ($topicRows->where('mode', 'nomination') as $round) {
                $ballot = $ballots->get($round->round_id);
                $nominationRounds[$round->category] = [
                    ...$this->roundData($round),
                    'generated_subject' => $ballot ? $this->roundData($ballot) : null,
                ];
            }

            return [...$topic, 'nomination_rounds' => $nominationRounds];
        })->values()->all();
    }

    private function roundData(NominationTopic $row): array
    {
        return [
            'id' => (int) $row->round_id,
            'slug' => $row->slug,
            'title' => $row->round_title,
            'mode' => $row->mode,
            'status' => $row->status,
            'category' => $row->category,
            'contestant_count' => (int) $row->contestant_count,
            'total_votes' => (int) $row->total_votes,
        ];
    }
}
