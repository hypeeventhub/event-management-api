<?php

use App\Models\Attendee;
use App\Models\Event;
use App\Models\User;
use App\Models\VotingSubject;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

function resultsEvent(User $owner, string $slug = 'results-event'): Event
{
    return Event::create([
        'created_by' => $owner->id,
        'title' => 'Awards Night',
        'slug' => $slug,
        'starts_at' => now()->addDay(),
        'ends_at' => now()->addDays(2),
    ]);
}

function resultsSubject(Event $event, string $slug = 'results-subject'): VotingSubject
{
    return $event->votingSubjects()->create([
        'slug' => hash('sha256', $slug),
        'title' => 'Best Performance',
        'status' => VotingSubject::STATUS_ACTIVE,
    ]);
}

function resultsRegistration(Event $event, int $number, string $status = 'confirmed'): int
{
    $form = $event->registrationForms()->firstOrCreate(['title' => 'Registration']);
    $email = "results-{$event->id}-{$number}@example.com";
    $attendee = Attendee::create([
        'first_name' => 'Voter',
        'last_name' => (string) $number,
        'email' => $email,
        'email_normalized' => $email,
    ]);

    return $event->registrations()->create([
        'registration_form_id' => $form->id,
        'attendee_id' => $attendee->id,
        'registration_code' => "RESULT-{$event->id}-{$number}",
        'status' => $status,
        'registered_at' => now(),
    ])->id;
}

function resultsUrl(Event $event, VotingSubject $subject): string
{
    return "/api/events/{$event->slug}/voting-subjects/{$subject->slug}/results";
}

test('owner sees exact ranked counts, two decimal percentages, and confirmed participation', function () {
    $owner = User::factory()->create();
    $event = resultsEvent($owner);
    $subject = resultsSubject($event);
    $laterOrder = $subject->contestants()->create(['name' => 'Alex', 'display_order' => 2]);
    $firstTie = $subject->contestants()->create(['name' => 'Blair', 'display_order' => 1]);
    $secondTie = $subject->contestants()->create(['name' => 'Casey', 'display_order' => 1]);
    $last = $subject->contestants()->create(['name' => 'Dana', 'display_order' => 0]);
    $registrations = [];
    for ($i = 1; $i <= 8; $i++) {
        $registrations[] = resultsRegistration($event, $i);
    }
    resultsRegistration($event, 9, 'cancelled');
    foreach ([$firstTie, $secondTie, $laterOrder, $last, $firstTie, $secondTie, $laterOrder] as $index => $contestant) {
        $subject->votes()->create([
            'voting_contestant_id' => $contestant->id,
            'registration_id' => $registrations[$index],
        ]);
    }
    $otherSubject = resultsSubject($event, 'other-results-subject');
    $otherContestant = $otherSubject->contestants()->create(['name' => 'Other']);
    $otherSubject->votes()->create([
        'voting_contestant_id' => $otherContestant->id,
        'registration_id' => $registrations[7],
    ]);
    $otherEvent = resultsEvent($owner, 'other-results-event');
    resultsRegistration($otherEvent, 1);

    $this->actingAs($owner)->getJson(resultsUrl($event, $subject))->assertOk()->assertExactJson([
        'event' => ['id' => $event->id, 'slug' => $event->slug, 'title' => 'Awards Night'],
        'subject' => ['id' => $subject->id, 'slug' => $subject->slug, 'title' => 'Best Performance', 'status' => 'active'],
        'total_votes' => 7,
        'total_registrations' => 8,
        'participation_percentage' => 87.5,
        'contestants' => [
            ['id' => $firstTie->id, 'name' => 'Blair', 'display_order' => 1, 'votes' => 2, 'percentage' => 28.57],
            ['id' => $secondTie->id, 'name' => 'Casey', 'display_order' => 1, 'votes' => 2, 'percentage' => 28.57],
            ['id' => $laterOrder->id, 'name' => 'Alex', 'display_order' => 2, 'votes' => 2, 'percentage' => 28.57],
            ['id' => $last->id, 'name' => 'Dana', 'display_order' => 0, 'votes' => 1, 'percentage' => 14.29],
        ],
    ]);
});

test('zero votes and zero confirmed registrations return zero percentages', function () {
    $owner = User::factory()->create();
    $event = resultsEvent($owner);
    $subject = resultsSubject($event);
    $contestant = $subject->contestants()->create(['name' => 'Alex', 'display_order' => 0]);
    resultsRegistration($event, 1, 'cancelled');

    $this->actingAs($owner)->getJson(resultsUrl($event, $subject))->assertOk()
        ->assertJsonPath('total_votes', 0)
        ->assertJsonPath('total_registrations', 0)
        ->assertJsonPath('participation_percentage', 0)
        ->assertJsonPath('contestants.0.id', $contestant->id)
        ->assertJsonPath('contestants.0.votes', 0)
        ->assertJsonPath('contestants.0.percentage', 0);
});

test('results require the owning admin and an event scoped subject', function () {
    $owner = User::factory()->create();
    $event = resultsEvent($owner);
    $subject = resultsSubject($event);
    $scanner = User::factory()->scanner()->create();
    $otherAdmin = User::factory()->create();
    $otherEvent = resultsEvent($owner, 'another-results-event');
    $url = resultsUrl($event, $subject);

    $this->getJson($url)->assertUnauthorized();
    $this->actingAs($scanner)->getJson($url)->assertForbidden();
    $this->actingAs($otherAdmin)->getJson($url)->assertNotFound();
    $this->actingAs($owner)->getJson(resultsUrl($otherEvent, $subject))->assertNotFound();
});

test('result query count stays bounded with many contestants', function () {
    $owner = User::factory()->create();
    $event = resultsEvent($owner);
    $subject = resultsSubject($event);
    for ($i = 0; $i < 30; $i++) {
        $subject->contestants()->create(['name' => "Contestant {$i}", 'display_order' => $i]);
    }

    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        $response = $this->actingAs($owner)->getJson(resultsUrl($event, $subject))->assertOk();
        $queries = DB::getQueryLog();
    } finally {
        DB::disableQueryLog();
    }

    expect($response->json('contestants'))->toHaveCount(30)
        ->and(count($queries))->toBeLessThan(10);
});

test('one vote snapshot supplies totals and percentages when a vote arrives between result queries', function () {
    $owner = User::factory()->create();
    $event = resultsEvent($owner);
    $subject = resultsSubject($event);
    $contestant = $subject->contestants()->create(['name' => 'Alex', 'display_order' => 0]);
    $firstRegistration = resultsRegistration($event, 1);
    $lateRegistration = resultsRegistration($event, 2);
    $subject->votes()->create(['voting_contestant_id' => $contestant->id, 'registration_id' => $firstRegistration]);

    $inserted = false;
    DB::listen(function (QueryExecuted $query) use (&$inserted, $subject, $contestant, $lateRegistration): void {
        if (! $inserted && str_starts_with(strtolower($query->sql), 'select') && str_contains($query->sql, 'voting_votes')) {
            $inserted = true;
            $subject->votes()->create(['voting_contestant_id' => $contestant->id, 'registration_id' => $lateRegistration]);
        }
    });

    $response = $this->actingAs($owner)->getJson(resultsUrl($event, $subject))->assertOk();
    expect($inserted)->toBeTrue();
    $response->assertJsonPath('total_votes', 1)->assertJsonPath('contestants.0.votes', 1)
        ->assertJsonPath('contestants.0.percentage', 100)->assertJsonPath('participation_percentage', 50);
    $this->getJson(resultsUrl($event, $subject))->assertOk()
        ->assertJsonPath('total_votes', 2)->assertJsonPath('contestants.0.votes', 2)->assertJsonPath('contestants.0.percentage', 100);
});

test('result vote aggregation scans the subject index once without correlated contestant scans', function () {
    $owner = User::factory()->create();
    $event = resultsEvent($owner);
    $subject = resultsSubject($event);
    for ($i = 0; $i < 30; $i++) {
        $contestant = $subject->contestants()->create(['name' => "Contestant {$i}", 'display_order' => $i]);
        $subject->votes()->create(['voting_contestant_id' => $contestant->id, 'registration_id' => resultsRegistration($event, $i)]);
    }

    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        $this->actingAs($owner)->getJson(resultsUrl($event, $subject))->assertOk()->assertJsonPath('total_votes', 30);
        $voteQueries = array_values(array_filter(DB::getQueryLog(), fn (array $query): bool => str_contains($query['query'], 'voting_votes')));
    } finally {
        DB::disableQueryLog();
    }

    expect($voteQueries)->toHaveCount(1);
    $query = $voteQueries[0];
    expect(strtolower($query['query']))->toContain('group by')->not->toContain('select *');
    $plan = DB::select('EXPLAIN QUERY PLAN '.$query['query'], $query['bindings']);
    $details = strtoupper(implode('\n', array_column($plan, 'detail')));
    expect($details)->not->toContain('CORRELATED')
        ->toContain('SEARCH VOTING_VOTES USING COVERING INDEX VOTING_VOTES_VOTING_SUBJECT_ID_VOTING_CONTESTANT_ID_INDEX (VOTING_SUBJECT_ID=?)');
});
