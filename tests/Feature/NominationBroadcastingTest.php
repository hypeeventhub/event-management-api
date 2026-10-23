<?php

use App\Events\AttendeeRegistered;
use App\Events\NominationClosed;
use App\Events\NominationStatisticsUpdated;
use App\Events\VotingClosed;
use App\Events\VotingStatisticsUpdated;
use App\Models\Attendee;
use App\Models\Event;
use App\Models\Registration;
use App\Models\User;
use App\Models\VotingContestant;
use App\Models\VotingSubject;
use App\Services\NominationService;
use App\Services\VotingStatistics;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Contracts\Broadcasting\Factory;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function broadcastSubject(string $mode = 'nomination', string $status = 'active', ?User $owner = null): VotingSubject
{
    $event = Event::create([
        'created_by' => ($owner ?? User::factory()->create())->id,
        'title' => 'Nomination Gala', 'slug' => Str::uuid()->toString(),
        'starts_at' => now()->addDay(), 'ends_at' => now()->addDays(2),
    ]);

    return $event->votingSubjects()->create([
        'title' => 'Best Teammate', 'slug' => hash('sha256', Str::uuid()),
        'mode' => $mode, 'status' => $status, 'top_n' => $mode === 'nomination' ? 2 : null,
    ]);
}

function broadcastRegistration(VotingSubject $subject, string $status = 'confirmed'): Registration
{
    $email = Str::uuid().'@example.com';
    $attendee = Attendee::create([
        'first_name' => 'Private', 'last_name' => 'Voter', 'email' => $email, 'email_normalized' => $email,
    ]);

    return $subject->event->registrations()->create([
        'registration_form_id' => $subject->event->registrationForms()->firstOrCreate(['title' => 'Registration'])->id,
        'attendee_id' => $attendee->id, 'registration_code' => Str::upper(Str::random(16)),
        'status' => $status, 'registered_at' => now(),
    ]);
}

function broadcastVote(VotingSubject $subject, Registration $registration, int $contestantId)
{
    if ($subject->mode === 'nomination') {
        $contestant = VotingContestant::query()->find($contestantId);
        $attendeeId = $contestant?->attendee_id;
        if ($contestant && ! $attendeeId) {
            $attendeeId = broadcastRegistration($subject)->attendee_id;
            $contestant->update(['attendee_id' => $attendeeId]);
        }

        return test()->postJson("/api/nominations/{$subject->slug}/submit", [
            'registration_code' => $registration->registration_code,
            'attendee_id' => $attendeeId ?? $contestantId,
        ]);
    }

    return test()->postJson("/api/voting/{$subject->slug}/votes", [
        'registration_code' => $registration->registration_code, 'contestant_id' => $contestantId,
    ]);
}

function queuedNominationBroadcast(): BroadcastEvent
{
    $payload = json_decode(DB::table('jobs')->sole()->payload, true);

    return unserialize($payload['data']['command']);
}

function queuedBroadcastEvents(): array
{
    return DB::table('jobs')->orderBy('id')->get()->map(function ($job) {
        $payload = json_decode($job->payload, true);

        return unserialize($payload['data']['command'])->event;
    })->all();
}

function useReverbChannelAuthentication(): void
{
    // The test app boots with the null broadcaster. Preserve the actual registered callbacks
    // when switching to the real Reverb signing driver; no socket server is needed for auth.
    $channels = Broadcast::getChannels();
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'test-public-key',
        'broadcasting.connections.reverb.secret' => 'test-secret',
        'broadcasting.connections.reverb.app_id' => 'test-app',
    ]);
    foreach ($channels as $pattern => $callback) {
        Broadcast::channel($pattern, $callback);
    }
}

beforeEach(function () {
    config(['queue.default' => 'database']);
});

test('canonical nomination statistics contain exact ranks shares and confirmed participation without voter data', function () {
    $subject = broadcastSubject();
    $alex = $subject->contestants()->create(['name' => 'Alex', 'display_order' => 2]);
    $bea = $subject->contestants()->create(['name' => 'Bea', 'display_order' => 0]);
    $cam = $subject->contestants()->create(['name' => 'Cam', 'display_order' => 1]);
    $dee = $subject->contestants()->create(['name' => 'Dee', 'display_order' => 3]);
    foreach ([$alex, $bea, $alex, $bea, $cam] as $contestant) {
        $subject->votes()->create([
            'registration_id' => broadcastRegistration($subject)->id, 'voting_contestant_id' => $contestant->id,
        ]);
    }
    broadcastRegistration($subject);
    broadcastRegistration($subject, 'cancelled');
    $expected = [
        'event' => ['id' => $subject->event_id, 'slug' => $subject->event->slug, 'title' => 'Nomination Gala'],
        'subject' => ['id' => $subject->id, 'slug' => $subject->slug, 'title' => 'Best Teammate', 'status' => 'active'],
        'total_votes' => 5, 'total_registrations' => 6, 'participation_percentage' => 83.33,
        'contestants' => [
            ['id' => $bea->id, 'name' => 'Bea', 'display_order' => 0, 'votes' => 2, 'rank' => 1, 'percentage' => 40],
            ['id' => $alex->id, 'name' => 'Alex', 'display_order' => 2, 'votes' => 2, 'rank' => 1, 'percentage' => 40],
            ['id' => $cam->id, 'name' => 'Cam', 'display_order' => 1, 'votes' => 1, 'rank' => 3, 'percentage' => 20],
            ['id' => $dee->id, 'name' => 'Dee', 'display_order' => 3, 'votes' => 0, 'rank' => 4, 'percentage' => 0],
        ],
    ];

    expect(app(VotingStatistics::class)->forSubject($subject))->toEqual($expected);
    $event = new NominationStatisticsUpdated($subject);
    expect($event)->toBeInstanceOf(ShouldBroadcast::class)
        ->toBeInstanceOf(ShouldDispatchAfterCommit::class)->toBeInstanceOf(ShouldRescue::class)
        ->and($event->broadcastWith())->toEqual([
            'event_id' => $subject->event_id,
            'subject_id' => $subject->id,
            'status' => 'active',
            'total_votes' => 5,
            'total_registrations' => 6,
            'contestant_votes' => [2, 1, 2, 0],
        ]);
    $this->actingAs(User::find($subject->event->created_by))
        ->getJson("/api/events/{$subject->event->slug}/voting-subjects/{$subject->slug}/results")
        ->assertOk()->assertExactJson($expected);
});

test('a successful nomination vote queues its private aggregate update only after commit', function () {
    $subject = broadcastSubject();
    $contestant = $subject->contestants()->create(['name' => 'Nominee']);
    $registration = broadcastRegistration($subject);
    DB::beginTransaction();
    broadcastVote($subject, $registration, $contestant->id)
        ->assertCreated()->assertExactJson(['message' => 'Your nomination has been submitted.']);
    $this->assertDatabaseCount('jobs', 0);
    DB::commit();

    $this->assertDatabaseCount('jobs', 1);
    $job = queuedNominationBroadcast();
    expect($job->event)->toBeInstanceOf(NominationStatisticsUpdated::class)
        ->and((string) $job->event->broadcastOn()[0])->toBe("private-events.{$subject->event_id}.nominations.{$subject->id}")
        ->and($job->event->broadcastWith()['total_votes'])->toBe(1);
    $this->getJson("/api/nominations/{$subject->slug}")->assertOk()->assertExactJson([
        'data' => ['title' => 'Best Teammate', 'status' => 'active'],
    ]);
});

test('closing a nomination queues one private close notice only after the close transaction commits', function () {
    $subject = broadcastSubject();
    $first = $subject->contestants()->create(['name' => 'First', 'display_order' => 0]);
    $subject->contestants()->create(['name' => 'Second', 'display_order' => 1]);
    $subject->votes()->create([
        'registration_id' => broadcastRegistration($subject)->id,
        'voting_contestant_id' => $first->id,
    ]);

    DB::beginTransaction();
    app(NominationService::class)->close($subject->event, $subject);
    expect($subject->fresh()->status)->toBe(VotingSubject::STATUS_CLOSED)
        ->and(DB::table('jobs')->count())->toBe(0);
    DB::commit();

    $this->assertDatabaseCount('jobs', 1);
    $event = queuedBroadcastEvents()[0];
    expect($event)->toBeInstanceOf(NominationClosed::class)
        ->and($event)->toBeInstanceOf(ShouldBroadcast::class)
        ->toBeInstanceOf(ShouldDispatchAfterCommit::class)
        ->toBeInstanceOf(ShouldRescue::class)
        ->and($event->broadcastWhen())->toBeTrue()
        ->and((string) $event->broadcastOn()[0])->toBe("private-events.{$subject->event_id}.nominations.{$subject->id}")
        ->and($event->broadcastWith())->toBe([
            'event_id' => $subject->event_id,
            'subject_id' => $subject->id,
            'status' => VotingSubject::STATUS_CLOSED,
        ]);
});

test('rolling back a successful nomination close removes its queued close notice', function () {
    $subject = broadcastSubject();
    $first = $subject->contestants()->create(['name' => 'First', 'display_order' => 0]);
    $subject->contestants()->create(['name' => 'Second', 'display_order' => 1]);
    $subject->votes()->create([
        'registration_id' => broadcastRegistration($subject)->id,
        'voting_contestant_id' => $first->id,
    ]);

    DB::beginTransaction();
    app(NominationService::class)->close($subject->event, $subject);
    $this->assertDatabaseCount('jobs', 0);
    DB::rollBack();

    expect($subject->fresh()->status)->toBe(VotingSubject::STATUS_ACTIVE)
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and($subject->event->votingSubjects()->where('generated_from_subject_id', $subject->id)->exists())->toBeFalse();
});

test('the largest nomination broadcast remains below Reverb default and excludes nominee names', function () {
    $subject = broadcastSubject();
    $longName = str_repeat('N', 511);
    $contestants = [];
    foreach (range(0, 1999) as $order) {
        $email = "broadcast-nominee-{$order}@example.com";
        $attendeeId = Attendee::query()->insertGetId([
            'first_name' => "Nominee{$order}",
            'last_name' => 'Unique',
            'email' => $email,
            'email_normalized' => $email,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $contestants[] = [
            'voting_subject_id' => $subject->id,
            'attendee_id' => $attendeeId,
            'name' => $longName,
            'display_order' => $order,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
    foreach (array_chunk($contestants, 200) as $chunk) {
        DB::table('voting_contestants')->insert($chunk);
    }

    $payload = (new NominationStatisticsUpdated($subject))->broadcastWith();
    $json = json_encode($payload, JSON_THROW_ON_ERROR);
    $largestCounts = $payload;
    $largestCounts['contestant_votes'] = array_fill(0, 2000, 1);
    $largestCounts['total_votes'] = 2000;
    $largestCounts['total_registrations'] = 2000;
    $largestCountsJson = json_encode($largestCounts, JSON_THROW_ON_ERROR);

    expect($payload['contestant_votes'])->toHaveCount(2000)
        ->and($payload['contestant_votes'])->toBe(array_fill(0, 2000, 0))
        ->and(array_keys($payload))->toBe(['event_id', 'subject_id', 'status', 'total_votes', 'total_registrations', 'contestant_votes'])
        ->and(strlen($json))->toBeLessThan(10_000)
        ->and(strlen($largestCountsJson))->toBeLessThan(10_000)
        ->and($json)->not->toContain($longName);
});

test('rolling back a saved nomination vote also discards its broadcast', function () {
    $subject = broadcastSubject();
    $contestant = $subject->contestants()->create(['name' => 'Nominee']);
    $registration = broadcastRegistration($subject);
    DB::beginTransaction();
    broadcastVote($subject, $registration, $contestant->id)->assertCreated();
    DB::rollBack();
    $this->assertDatabaseCount('voting_votes', 0);
    $this->assertDatabaseCount('jobs', 0);
});

test('manual votes queue their private aggregate update only after commit', function () {
    $subject = broadcastSubject('manual');
    $first = $subject->contestants()->create(['name' => 'Contestant One', 'display_order' => 0]);
    $second = $subject->contestants()->create(['name' => 'Contestant Two', 'display_order' => 1]);
    $registration = broadcastRegistration($subject);

    DB::beginTransaction();
    broadcastVote($subject, $registration, $first->id)->assertCreated();
    $this->assertDatabaseCount('jobs', 0);
    DB::commit();

    $this->assertDatabaseCount('voting_votes', 1);
    $this->assertDatabaseCount('jobs', 1);
    broadcastVote($subject, $registration, $first->id)->assertConflict();
    $this->assertDatabaseCount('jobs', 1);
    $event = queuedBroadcastEvents()[0];
    expect($event)->toBeInstanceOf(VotingStatisticsUpdated::class)
        ->toBeInstanceOf(ShouldBroadcast::class)
        ->toBeInstanceOf(ShouldDispatchAfterCommit::class)
        ->toBeInstanceOf(ShouldRescue::class)
        ->and((string) $event->broadcastOn()[0])->toBe("private-events.{$subject->event_id}.voting.{$subject->id}")
        ->and($event->broadcastWith())->toBe([
            'event_id' => $subject->event_id,
            'subject_id' => $subject->id,
            'status' => 'active',
            'total_votes' => 1,
            'total_registrations' => 1,
            'contestant_votes' => [1, 0],
        ])
        ->and(json_encode($event->broadcastWith(), JSON_THROW_ON_ERROR))->not->toContain('Contestant One');
});

test('closing manual voting queues a private close notice only after commit', function () {
    $owner = User::factory()->create();
    $subject = broadcastSubject('manual', 'active', $owner);

    DB::beginTransaction();
    $this->actingAs($owner)->postJson("/api/events/{$subject->event->slug}/voting-subjects/{$subject->slug}/close")
        ->assertOk()->assertJsonPath('data.status', 'closed');
    $this->assertDatabaseCount('jobs', 0);
    DB::commit();

    $this->assertDatabaseCount('jobs', 1);
    $event = queuedBroadcastEvents()[0];
    expect($event)->toBeInstanceOf(VotingClosed::class)
        ->toBeInstanceOf(ShouldBroadcast::class)
        ->toBeInstanceOf(ShouldDispatchAfterCommit::class)
        ->toBeInstanceOf(ShouldRescue::class)
        ->and((string) $event->broadcastOn()[0])->toBe("private-events.{$subject->event_id}.voting.{$subject->id}")
        ->and($event->broadcastWith())->toBe([
            'event_id' => $subject->event_id,
            'subject_id' => $subject->id,
            'status' => 'closed',
        ]);
});

test('private manual voting channel authentication admits only its active event owner', function () {
    useReverbChannelAuthentication();
    $owner = User::factory()->create();
    $subject = broadcastSubject('manual', 'active', $owner);
    $payload = ['socket_id' => '123.456', 'channel_name' => "private-events.{$subject->event_id}.voting.{$subject->id}"];

    $this->postJson('/api/broadcasting/auth', $payload)->assertUnauthorized();
    $this->actingAs(User::factory()->create())->postJson('/api/broadcasting/auth', $payload)->assertForbidden();
    $this->actingAs(User::factory()->scanner()->create())->postJson('/api/broadcasting/auth', $payload)->assertForbidden();
    $this->actingAs($owner)->postJson('/api/broadcasting/auth', $payload)->assertOk()->assertJsonStructure(['auth']);

    $subject->update(['status' => 'closed']);
    $this->actingAs($owner)->postJson('/api/broadcasting/auth', $payload)->assertForbidden();
});

test('invalid duplicate and closed nomination votes never add broadcast jobs', function () {
    $subject = broadcastSubject();
    $contestant = $subject->contestants()->create(['name' => 'Nominee']);
    $registration = broadcastRegistration($subject);
    broadcastVote($subject, broadcastRegistration($subject, 'cancelled'), $contestant->id)->assertUnprocessable();
    broadcastVote($subject, $registration, $contestant->id + 1000)->assertUnprocessable();
    $this->assertDatabaseCount('jobs', 0);
    broadcastVote($subject, $registration, $contestant->id)->assertCreated();
    broadcastVote($subject, $registration, $contestant->id)->assertConflict();
    $subject->update(['status' => 'closed']);
    broadcastVote($subject, broadcastRegistration($subject), $contestant->id)->assertNotFound();
    $this->assertDatabaseCount('voting_votes', 1);
    $this->assertDatabaseCount('jobs', 1);
});

test('a queued update is suppressed if the nomination closes before the worker handles it', function () {
    $subject = broadcastSubject();
    $contestant = $subject->contestants()->create(['name' => 'Nominee']);
    broadcastVote($subject, broadcastRegistration($subject), $contestant->id)->assertCreated();
    $job = queuedNominationBroadcast();
    expect($job->event->broadcastWhen())->toBeTrue();
    $subject->update(['status' => 'closed']);
    expect($job->event->broadcastWhen())->toBeFalse();
    $manager = Mockery::mock(Factory::class);
    $manager->shouldNotReceive('connection');
    $job->handle($manager);
});

test('a later transport failure cannot undo the successful saved nomination vote', function () {
    $subject = broadcastSubject();
    $contestant = $subject->contestants()->create(['name' => 'Nominee']);
    broadcastVote($subject, broadcastRegistration($subject), $contestant->id)->assertCreated();
    $job = queuedNominationBroadcast();
    $manager = Mockery::mock(Factory::class);
    $manager->shouldReceive('connection')->once()->andThrow(new RuntimeException('WebSocket unavailable'));
    expect(fn () => $job->handle($manager))->toThrow(RuntimeException::class, 'WebSocket unavailable');
    $this->assertDatabaseCount('voting_votes', 1);
});

test('broadcast enqueue failures are rescued after saving the vote', function () {
    $subject = broadcastSubject();
    $contestant = $subject->contestants()->create(['name' => 'Nominee']);
    // The real database queue attempts to write a missing jobs table.
    config(['queue.connections.database.table' => 'missing_broadcast_jobs']);
    broadcastVote($subject, broadcastRegistration($subject), $contestant->id)->assertCreated();
    $this->assertDatabaseCount('voting_votes', 1);
});

test('private channel authentication admits only the active nomination event owner', function () {
    useReverbChannelAuthentication();
    $owner = User::factory()->create();
    $subject = broadcastSubject(owner: $owner);
    $payload = ['socket_id' => '123.456', 'channel_name' => "private-events.{$subject->event_id}.nominations.{$subject->id}"];
    $this->postJson('/api/broadcasting/auth', $payload)->assertUnauthorized();
    $this->actingAs(User::factory()->create())->postJson('/api/broadcasting/auth', $payload)->assertForbidden();
    $this->actingAs(User::factory()->scanner()->create())->postJson('/api/broadcasting/auth', $payload)->assertForbidden();
    $this->actingAs($owner)->postJson('/api/broadcasting/auth', $payload)->assertOk()->assertJsonStructure(['auth']);
    $subject->update(['status' => 'closed']);
    $this->postJson('/api/broadcasting/auth', $payload)->assertForbidden();
});

test('attendee registration broadcasts a private refresh signal only after commit without personal data', function () {
    $owner = User::factory()->create();
    $subject = broadcastSubject('manual', 'active', $owner);

    DB::beginTransaction();
    $registration = broadcastRegistration($subject);
    event(new AttendeeRegistered($registration));
    $this->assertDatabaseCount('jobs', 0);
    DB::commit();

    $this->assertDatabaseCount('jobs', 1);
    $broadcast = queuedBroadcastEvents()[0];
    expect($broadcast)->toBeInstanceOf(AttendeeRegistered::class)
        ->toBeInstanceOf(ShouldBroadcast::class)
        ->toBeInstanceOf(ShouldDispatchAfterCommit::class)
        ->toBeInstanceOf(ShouldRescue::class)
        ->and((string) $broadcast->broadcastOn()[0])->toBe("private-events.{$subject->event_id}.attendees")
        ->and($broadcast->broadcastAs())->toBe('attendee.registered')
        ->and($broadcast->broadcastWith())->toBe(['registration_id' => $registration->id]);
});

test('private attendee-list channel authentication admits only the owning admin', function () {
    useReverbChannelAuthentication();
    $owner = User::factory()->create();
    $event = broadcastSubject('manual', 'active', $owner)->event;
    $payload = ['socket_id' => '123.456', 'channel_name' => "private-events.{$event->id}.attendees"];

    $this->postJson('/api/broadcasting/auth', $payload)->assertUnauthorized();
    $this->actingAs(User::factory()->create())->postJson('/api/broadcasting/auth', $payload)->assertForbidden();
    $this->actingAs(User::factory()->scanner()->create())->postJson('/api/broadcasting/auth', $payload)->assertForbidden();
    $this->actingAs($owner)->postJson('/api/broadcasting/auth', $payload)->assertOk()->assertJsonStructure(['auth']);
});

test('private channel authentication rejects drafts manual subjects and mismatched events even for their owner', function () {
    useReverbChannelAuthentication();
    $owner = User::factory()->create();
    $draft = broadcastSubject(status: 'draft', owner: $owner);
    $manual = broadcastSubject(mode: 'manual', owner: $owner);
    $active = broadcastSubject(owner: $owner);
    $this->actingAs($owner);
    foreach ([[$draft->event_id, $draft->id], [$manual->event_id, $manual->id], [$draft->event_id, $active->id], [$active->event_id, 99999]] as [$eventId, $subjectId]) {
        $this->postJson('/api/broadcasting/auth', [
            'socket_id' => '123.456', 'channel_name' => "private-events.{$eventId}.nominations.{$subjectId}",
        ])->assertForbidden();
    }
    $active->event->update(['created_by' => User::factory()->scanner()->create()->id]);
    $this->actingAs(User::find($active->event->created_by))->postJson('/api/broadcasting/auth', [
        'socket_id' => '123.456', 'channel_name' => "private-events.{$active->event_id}.nominations.{$active->id}",
    ])->assertForbidden();
});
