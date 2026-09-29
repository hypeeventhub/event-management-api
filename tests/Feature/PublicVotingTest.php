<?php

use App\Models\Attendee;
use App\Models\Event;
use App\Models\Registration;
use App\Models\User;
use App\Models\VotingSubject;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

function publicVotingEvent(string $suffix = 'main'): Event
{
    $owner = User::factory()->create();

    return Event::create([
        'created_by' => $owner->id,
        'title' => 'Voting Event '.$suffix,
        'slug' => 'voting-event-'.$suffix,
        'starts_at' => now()->addDay(),
        'ends_at' => now()->addDays(2),
    ]);
}

function publicVotingSubject(Event $event, string $suffix = 'main', string $status = VotingSubject::STATUS_ACTIVE): VotingSubject
{
    $subject = $event->votingSubjects()->create([
        'slug' => hash('sha256', $suffix),
        'title' => 'Best Performance '.$suffix,
        'status' => $status,
    ]);
    $subject->contestants()->create(['name' => 'Second', 'display_order' => 1]);
    $subject->contestants()->create(['name' => 'First', 'display_order' => 0]);

    return $subject;
}

function publicVotingRegistration(Event $event, string $code, string $status = 'confirmed'): Registration
{
    $form = $event->registrationForms()->firstOrCreate(['title' => 'Registration']);
    $email = strtolower($code).'@example.com';
    $attendee = Attendee::create([
        'first_name' => 'Jamie',
        'last_name' => 'Rivera',
        'email' => $email,
        'email_normalized' => $email,
    ]);

    return $event->registrations()->create([
        'registration_form_id' => $form->id,
        'attendee_id' => $attendee->id,
        'registration_code' => $code,
        'status' => $status,
        'registered_at' => now(),
    ]);
}

test('an active ballot exposes only its title and ordered contestant identifiers and names', function () {
    $event = publicVotingEvent();
    $subject = publicVotingSubject($event);
    $contestants = $subject->contestants()->orderBy('display_order')->get();
    $registration = publicVotingRegistration($event, 'REG-EXISTING');
    $subject->votes()->create([
        'voting_contestant_id' => $contestants[0]->id,
        'registration_id' => $registration->id,
    ]);

    $this->getJson("/api/voting/{$subject->slug}")->assertOk()->assertExactJson([
        'title' => 'Best Performance main',
        'contestants' => [
            ['id' => $contestants[0]->id, 'name' => 'First'],
            ['id' => $contestants[1]->id, 'name' => 'Second'],
        ],
    ]);
});

test('draft closed and unknown ballots have identical public not found responses', function () {
    $event = publicVotingEvent();
    $draft = publicVotingSubject($event, 'draft', VotingSubject::STATUS_DRAFT);
    $closed = publicVotingSubject($event, 'closed', VotingSubject::STATUS_CLOSED);
    $unknown = hash('sha256', 'unknown');
    $payload = ['registration_code' => 'REG-UNKNOWN', 'contestant_id' => 123];

    $getResponses = [];
    $postResponses = [];
    foreach ([$draft->slug, $closed->slug, $unknown] as $slug) {
        $getResponses[] = $this->getJson("/api/voting/{$slug}")->assertNotFound()->json();
        $postResponses[] = $this->postJson("/api/voting/{$slug}/votes", $payload)->assertNotFound()->json();
    }

    expect($getResponses[0])->toBe(['message' => 'Not Found'])->toBe($getResponses[1])->toBe($getResponses[2])
        ->and($postResponses[0])->toBe(['message' => 'Not Found'])->toBe($postResponses[1])->toBe($postResponses[2]);
});

test('a confirmed registration votes without check in and its code is normalized', function () {
    $event = publicVotingEvent();
    $subject = publicVotingSubject($event);
    $contestant = $subject->contestants()->firstWhere('name', 'First');
    $registration = publicVotingRegistration($event, 'REG-NOCHECKIN');

    $this->postJson("/api/voting/{$subject->slug}/votes", [
        'registration_code' => '  reg-nocheckin  ',
        'contestant_id' => $contestant->id,
    ])->assertCreated()->assertExactJson(['message' => 'Your vote has been recorded.']);

    $this->assertDatabaseHas('voting_votes', [
        'event_id' => $event->id,
        'voting_subject_id' => $subject->id,
        'voting_contestant_id' => $contestant->id,
        'registration_id' => $registration->id,
    ]);
    $this->assertDatabaseCount('check_ins', 0);
});

test('unknown other event and cancelled registrations share the same invalid code response', function () {
    $event = publicVotingEvent();
    $subject = publicVotingSubject($event);
    $contestant = $subject->contestants()->first();
    publicVotingRegistration($event, 'REG-CANCELLED', 'cancelled');
    $otherEvent = publicVotingEvent('other');
    publicVotingRegistration($otherEvent, 'REG-OTHER');

    $responses = [];
    foreach (['REG-UNKNOWN', 'REG-CANCELLED', 'REG-OTHER'] as $code) {
        $responses[] = $this->postJson("/api/voting/{$subject->slug}/votes", [
            'registration_code' => $code,
            'contestant_id' => $contestant->id,
        ])->assertUnprocessable()->json();
    }

    expect($responses[0])->toBe(['message' => 'Invalid registration code.'])
        ->toBe($responses[1])->toBe($responses[2]);
    $this->assertDatabaseCount('voting_votes', 0);
});

test('a contestant outside the ballot is rejected without exposing its subject', function () {
    $event = publicVotingEvent();
    $subject = publicVotingSubject($event);
    $otherSubject = publicVotingSubject($event, 'other-subject');
    $otherContestant = $otherSubject->contestants()->first();
    $registration = publicVotingRegistration($event, 'REG-CONTESTANT');

    $otherResponse = $this->postJson("/api/voting/{$subject->slug}/votes", [
        'registration_code' => $registration->registration_code,
        'contestant_id' => $otherContestant->id,
    ])->assertUnprocessable()->json();
    $unknownResponse = $this->postJson("/api/voting/{$subject->slug}/votes", [
        'registration_code' => $registration->registration_code,
        'contestant_id' => 999999,
    ])->assertUnprocessable()->json();

    expect($otherResponse)->toBe(['message' => 'Invalid contestant.'])->toBe($unknownResponse);
    $this->assertDatabaseCount('voting_votes', 0);
});

test('a duplicate vote returns a stable conflict and the same registration can vote in another subject', function () {
    $event = publicVotingEvent();
    $subject = publicVotingSubject($event);
    $otherSubject = publicVotingSubject($event, 'another-award');
    $registration = publicVotingRegistration($event, 'REG-TWOSUBJECTS');
    $contestant = $subject->contestants()->first();
    $otherContestant = $otherSubject->contestants()->first();
    $url = "/api/voting/{$subject->slug}/votes";

    $this->postJson($url, [
        'registration_code' => $registration->registration_code,
        'contestant_id' => $contestant->id,
    ])->assertCreated();
    $this->postJson($url, [
        'registration_code' => $registration->registration_code,
        'contestant_id' => $contestant->id,
    ])->assertStatus(409)->assertExactJson(['message' => 'You have already voted for this subject.']);
    $this->postJson("/api/voting/{$otherSubject->slug}/votes", [
        'registration_code' => $registration->registration_code,
        'contestant_id' => $otherContestant->id,
    ])->assertCreated();

    $this->assertDatabaseCount('voting_votes', 2);
    expect($subject->votes()->where('registration_id', $registration->id)->count())->toBe(1);
});

test('closing a subject after route lookup prevents the vote at the locked recheck', function () {
    $event = publicVotingEvent();
    $subject = publicVotingSubject($event);
    $registration = publicVotingRegistration($event, 'REG-LATECLOSE');
    $contestant = $subject->contestants()->first();
    $closedDuringBinding = false;
    VotingSubject::retrieved(function (VotingSubject $retrieved) use ($subject, &$closedDuringBinding): void {
        if (! $closedDuringBinding && $retrieved->is($subject)) {
            $closedDuringBinding = true;
            VotingSubject::query()->whereKey($subject->id)->update(['status' => VotingSubject::STATUS_CLOSED]);
        }
    });

    $this->postJson("/api/voting/{$subject->slug}/votes", [
        'registration_code' => $registration->registration_code,
        'contestant_id' => $contestant->id,
    ])->assertNotFound();

    expect($closedDuringBinding)->toBeTrue();
    $this->assertDatabaseCount('voting_votes', 0);
});

test('vote submission validates required code and integer contestant id', function () {
    $event = publicVotingEvent();
    $subject = publicVotingSubject($event);

    $this->postJson("/api/voting/{$subject->slug}/votes", [])
        ->assertUnprocessable()->assertJsonValidationErrors(['registration_code', 'contestant_id']);
    $this->postJson("/api/voting/{$subject->slug}/votes", [
        'registration_code' => 'REG-CODE',
        'contestant_id' => 'not-an-id',
    ])->assertUnprocessable()->assertJsonValidationErrors(['contestant_id']);
    $this->assertDatabaseCount('voting_votes', 0);
});

test('frontend origin guests can vote with CSRF enforcement enabled while session routes remain protected', function () {
    config(['sanctum.stateful' => ['localhost:3000']]);
    $this->app->instance(ValidateCsrfToken::class, new class($this->app, $this->app['encrypter']) extends ValidateCsrfToken
    {
        protected function runningUnitTests(): bool
        {
            return false;
        }
    });
    $headers = ['Origin' => 'http://localhost:3000', 'Referer' => 'http://localhost:3000/vote/ballot'];
    $browserRequest = Request::create('/api/voting/ballot', 'POST', server: ['HTTP_ORIGIN' => $headers['Origin']]);
    expect(EnsureFrontendRequestsAreStateful::fromFrontend($browserRequest))->toBeTrue();

    $event = publicVotingEvent();
    $subject = publicVotingSubject($event);
    $registration = publicVotingRegistration($event, 'REG-BROWSER');
    $lookup = $this->getJson("/api/voting/{$subject->slug}", $headers)->assertOk();
    $this->postJson("/api/voting/{$subject->slug}/votes", [
        'registration_code' => $registration->registration_code,
        'contestant_id' => $subject->contestants()->first()->id,
    ], $headers)->assertCreated()->assertCookieMissing(config('session.cookie'));
    $lookup->assertCookieMissing(config('session.cookie'));

    $this->postJson('/api/login', [], $headers)->assertStatus(419);
    $this->actingAs(User::findOrFail($event->created_by));
    $url = "/api/events/{$event->slug}/voting-subjects";
    $payload = ['title' => 'Browser award', 'contestants' => [['name' => 'Ava'], ['name' => 'Bea']]];
    $this->postJson($url, $payload, $headers)->assertStatus(419);
    $this->withSession(['_token' => 'browser-csrf-token'])
        ->postJson($url, $payload, [...$headers, 'X-CSRF-TOKEN' => 'browser-csrf-token'])->assertCreated();
});

test('lookup and submission throttle budgets are isolated by operation and subject', function () {
    $event = publicVotingEvent();
    $subject = publicVotingSubject($event);
    $other = publicVotingSubject($event, 'other-limit');
    $registration = publicVotingRegistration($event, 'REG-LOOKUPS');

    for ($i = 0; $i < 60; $i++) {
        $this->getJson("/api/voting/{$subject->slug}")->assertOk();
    }
    $this->getJson("/api/voting/{$subject->slug}")->assertTooManyRequests();
    $this->getJson("/api/voting/{$other->slug}")->assertOk();
    foreach ([$subject, $other] as $ballot) {
        $this->postJson("/api/voting/{$ballot->slug}/votes", [
            'registration_code' => $registration->registration_code,
            'contestant_id' => $ballot->contestants()->first()->id,
        ])->assertCreated();
    }
});

test('a venue IP can submit votes for many different registrations without sharing their budget', function () {
    $event = publicVotingEvent();
    $subject = publicVotingSubject($event);
    $contestant = $subject->contestants()->first();
    for ($i = 0; $i < 25; $i++) {
        $registration = publicVotingRegistration($event, "REG-VENUE-{$i}");
        $this->postJson("/api/voting/{$subject->slug}/votes", [
            'registration_code' => $registration->registration_code,
            'contestant_id' => $contestant->id,
        ])->assertCreated();
    }
    $this->assertDatabaseCount('voting_votes', 25);
});

test('submission limits normalize registration codes and isolate other registrations and subjects', function () {
    $event = publicVotingEvent();
    $subject = publicVotingSubject($event);
    $other = publicVotingSubject($event, 'other-submission-limit');
    $registration = publicVotingRegistration($event, 'REG-LIMIT');
    $another = publicVotingRegistration($event, 'REG-ANOTHER');
    $url = "/api/voting/{$subject->slug}/votes";
    for ($i = 0; $i < 10; $i++) {
        $this->postJson($url, [
            'registration_code' => $i % 2 ? '  reg-limit  ' : 'REG-LIMIT',
            'contestant_id' => 99999 + $i,
        ])->assertUnprocessable();
    }
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->postJson($url, [
        'registration_code' => ' reg-LIMIT ', 'contestant_id' => $subject->contestants()->first()->id,
    ])->assertTooManyRequests()->assertHeader('Retry-After');
    $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])->postJson($url, [
        'registration_code' => $another->registration_code, 'contestant_id' => $subject->contestants()->first()->id,
    ])->assertCreated();
    $this->postJson("/api/voting/{$other->slug}/votes", [
        'registration_code' => $registration->registration_code, 'contestant_id' => $other->contestants()->first()->id,
    ])->assertCreated();
});

test('invalid code attempts have no lower shared threshold than valid registration attempts', function () {
    $event = publicVotingEvent();
    $subject = publicVotingSubject($event);
    $registration = publicVotingRegistration($event, 'REG-VALID-AFTER-GUESSES');
    $url = "/api/voting/{$subject->slug}/votes";
    for ($i = 0; $i < 25; $i++) {
        $this->postJson($url, ['registration_code' => "UNKNOWN-{$i}", 'contestant_id' => 99999])
            ->assertUnprocessable()->assertExactJson(['message' => 'Invalid contestant.']);
    }
    foreach ([$registration->registration_code, 'UNKNOWN-NEW'] as $code) {
        $this->postJson($url, ['registration_code' => $code, 'contestant_id' => 99999])
            ->assertUnprocessable()->assertExactJson(['message' => 'Invalid contestant.']);
    }
});

test('exhausted venue submission budget rejects every code before registration lookup', function () {
    config(['app.debug' => false]);
    $this->freezeTime();
    $event = publicVotingEvent();
    $subject = publicVotingSubject($event);
    $other = publicVotingSubject($event, 'other-venue-budget');
    $registration = publicVotingRegistration($event, 'REG-VALID-AFTER-BUDGET');
    $url = "/api/voting/{$subject->slug}/votes";
    $statuses = [];
    for ($i = 0; $i < 120; $i++) {
        $statuses[] = $this->postJson($url, ['registration_code' => "UNKNOWN-{$i}", 'contestant_id' => 99999])->status();
    }

    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        $responses = [];
        foreach ([$registration->registration_code, 'UNKNOWN-NEW', ['invalid']] as $code) {
            $responses[] = $this->postJson($url, ['registration_code' => $code, 'contestant_id' => 99999])
                ->assertTooManyRequests()->assertExactJson(['message' => 'Too Many Attempts.']);
        }
        $registrationQueries = array_filter(DB::getQueryLog(), fn (array $query): bool => str_contains($query['query'], 'registrations'));
    } finally {
        DB::disableQueryLog();
    }

    expect(array_unique($statuses))->toBe([422])
        ->and($registrationQueries)->toBe([]);
    foreach (['X-RateLimit-Limit', 'X-RateLimit-Remaining', 'Retry-After'] as $header) {
        expect($responses[0]->headers->get($header))->not->toBeNull()
            ->toBe($responses[1]->headers->get($header))->toBe($responses[2]->headers->get($header));
    }

    $this->getJson("/api/voting/{$subject->slug}")->assertOk();
    $this->postJson("/api/voting/{$other->slug}/votes", [
        'registration_code' => $registration->registration_code, 'contestant_id' => $other->contestants()->first()->id,
    ])->assertCreated();
});

test('repeated normalized candidate codes have the same fine limit regardless of eligibility', function () {
    config(['app.debug' => false]);
    $event = publicVotingEvent();
    $subject = publicVotingSubject($event);
    $registration = publicVotingRegistration($event, 'REG-FINE-LIMIT');
    $url = "/api/voting/{$subject->slug}/votes";
    foreach ([$registration->registration_code, 'UNKNOWN-FINE-LIMIT'] as $code) {
        for ($i = 0; $i < 10; $i++) {
            $this->postJson($url, [
                'registration_code' => $i % 2 ? '  '.strtolower($code).'  ' : $code,
                'contestant_id' => 99999,
            ])->assertUnprocessable()->assertExactJson(['message' => 'Invalid contestant.']);
        }
        $this->postJson($url, ['registration_code' => $code, 'contestant_id' => 99999])
            ->assertTooManyRequests()->assertExactJson(['message' => 'Too Many Attempts.']);
    }
});
