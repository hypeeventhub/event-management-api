<?php

namespace App\Services;

use App\Models\Attendee;
use App\Models\Event;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class NomineeSearchService
{
    public function search(Event $event, string $query): Collection
    {
        $query = $this->normalize($query);
        if ($query === '') {
            return collect();
        }

        // An existence query keeps attendees distinct even with repeat registrations.
        return Attendee::query()
            ->whereHas('registrations', fn ($registrations) => $registrations->where('event_id', $event->id))
            ->get(['id', 'first_name', 'last_name'])
            ->map(function (Attendee $attendee) use ($query): array {
                $fullName = Str::squish($attendee->first_name.' '.$attendee->last_name);
                $name = $this->normalize($fullName);
                $rank = $name === $query ? 0 : (str_contains($name, $query) ? 1 : 2);
                $distance = levenshtein($query, $name);

                return [
                    'id' => $attendee->id,
                    'full_name' => $fullName,
                    'rank' => $rank,
                    'distance' => $distance,
                    'matches' => $rank < 2 || $distance <= max(1, (int) floor(max(strlen($query), strlen($name)) * 0.25)),
                ];
            })
            ->where('matches', true)
            ->sortBy([['rank', 'asc'], ['distance', 'asc'], ['id', 'asc']])
            ->take(20)
            ->map(fn (array $candidate): array => [
                'id' => $candidate['id'],
                'full_name' => $candidate['full_name'],
            ])
            ->values();
    }

    private function normalize(string $name): string
    {
        return mb_strtolower(Str::squish($name));
    }
}
