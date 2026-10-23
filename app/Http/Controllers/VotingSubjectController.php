<?php

namespace App\Http\Controllers;

use App\Events\VotingClosed;
use App\Http\Requests\StoreVotingSubjectRequest;
use App\Http\Requests\UpdateVotingSubjectRequest;
use App\Http\Resources\VotingSubjectResource;
use App\Models\Event;
use App\Models\VotingSubject;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class VotingSubjectController extends Controller
{
    public function index(Request $request, Event $event): JsonResponse
    {
        $this->owner($request, $event);

        $subjects = $event->votingSubjects()->orderByDesc('id')->get()
            ->map(fn (VotingSubject $subject): array => $this->subjectData($subject));

        return response()->json(['data' => $subjects]);
    }

    public function store(StoreVotingSubjectRequest $request, Event $event): JsonResponse
    {
        $data = $request->validated();
        $subject = DB::transaction(function () use ($event, $data): VotingSubject {
            do {
                $slug = hash('sha256', Str::random(64));
            } while (VotingSubject::where('slug', $slug)->exists());

            $subject = $event->votingSubjects()->create([
                'slug' => $slug,
                'title' => $data['title'],
                'status' => VotingSubject::STATUS_DRAFT,
                'mode' => 'manual',
            ]);
            $this->replaceContestants($subject, $data['contestants']);

            return $subject;
        });

        return response()->json(['data' => $this->subjectData($subject)], 201);
    }

    public function show(Request $request, Event $event, string $subject): JsonResponse
    {
        $this->owner($request, $event);

        return response()->json(['data' => $this->subjectData($this->findSubject($event, $subject))]);
    }

    public function update(UpdateVotingSubjectRequest $request, Event $event, string $subject): JsonResponse
    {
        $data = $request->validated();

        return DB::transaction(function () use ($event, $subject, $data): JsonResponse {
            $record = $this->findSubject($event, $subject, true);
            abort_unless($record->mode === 'manual', 404);
            if ($record->status !== VotingSubject::STATUS_DRAFT) {
                return $this->conflict('Only draft voting subjects can be edited.');
            }

            $record->update(['title' => $data['title']]);
            $record->contestants()->delete();
            $this->replaceContestants($record, $data['contestants']);

            return response()->json(['data' => $this->subjectData($record)]);
        });
    }

    public function destroy(Request $request, Event $event, string $subject): JsonResponse
    {
        $this->owner($request, $event);

        return DB::transaction(function () use ($event, $subject): JsonResponse {
            $record = $this->findSubject($event, $subject, true);
            if ($record->status !== VotingSubject::STATUS_DRAFT) {
                return $this->conflict('Only draft voting subjects can be deleted.');
            }

            $record->delete();

            return response()->json(null, 204);
        });
    }

    public function activate(Request $request, Event $event, string $subject): JsonResponse
    {
        $this->owner($request, $event);

        return DB::transaction(function () use ($event, $subject): JsonResponse {
            $record = $this->findSubject($event, $subject, true);
            abort_unless($record->mode === 'manual', 404);
            if ($record->status !== VotingSubject::STATUS_DRAFT) {
                return $this->conflict('Only draft voting subjects can be activated.');
            }
            if ($record->contestants()->count() < 2) {
                return $this->conflict('At least two contestants are required to activate voting.');
            }

            $record->update(['status' => VotingSubject::STATUS_ACTIVE]);

            return response()->json(['data' => $this->subjectData($record)]);
        });
    }

    public function close(Request $request, Event $event, string $subject): JsonResponse
    {
        $this->owner($request, $event);

        return DB::transaction(function () use ($event, $subject): JsonResponse {
            $record = $this->findSubject($event, $subject, true);
            abort_unless($record->mode === 'manual', 404);
            if ($record->status !== VotingSubject::STATUS_ACTIVE) {
                return $this->conflict('Only active voting subjects can be closed.');
            }

            $record->update(['status' => VotingSubject::STATUS_CLOSED]);
            event(new VotingClosed($record));

            return response()->json(['data' => $this->subjectData($record)]);
        });
    }

    private function owner(Request $request, Event $event): void
    {
        abort_unless($event->created_by === $request->user()->id, 404);
    }

    private function findSubject(Event $event, string $slug, bool $lock = false): VotingSubject
    {
        $query = $event->votingSubjects()->where('slug', $slug);

        return ($lock ? $query->lockForUpdate() : $query)->firstOrFail();
    }

    private function replaceContestants(VotingSubject $subject, array $contestants): void
    {
        $order = 0;

        foreach ($contestants as $contestant) {
            $subject->contestants()->create(['name' => $contestant['name'], 'display_order' => $order++]);
        }
    }

    private function subjectData(VotingSubject $subject): array
    {
        return (new VotingSubjectResource($subject))->resolve();
    }

    private function conflict(string $message): JsonResponse
    {
        return response()->json(['message' => $message], 409);
    }
}
