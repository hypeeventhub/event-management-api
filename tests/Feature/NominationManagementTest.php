<?php

use App\Models\Attendee;
use App\Models\Event;
use App\Models\Registration;
use App\Models\User;
use App\Models\VotingContestant;
use App\Models\VotingSubject;
use App\Services\NominationService;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

function nominationManagementEvent(?User $owner = null): Event
{
    return Event::create([
        'created_by' => ($owner ?? User::factory()->create())->id,
        'title' => 'Nomination Gala',
        'slug' => Str::uuid()->toString(),
        'starts_at' => now()->addDay(),
        'ends_at' => now()->addDays(2),
    ]);
}

function nominationManagementRegistration(Event $event, string $firstName, string $status = 'confirmed'): Registration
{
    $email = Str::uuid().'@example.com';
    $attendee = Attendee::create([
        'first_name' => $firstName,
        'last_name' => 'Lee',
        'email' => $email,
        'email_normalized' => $email,
    ]);

    return $event->registrations()->create([
        'registration_form_id' => $event->registrationForms()->firstOrCreate(['title' => 'Registration'])->id,
        'attendee_id' => $attendee->id,
        'registration_code' => 'REG-'.Str::upper(Str::random(12)),
        'status' => $status,
        'registered_at' => now(),
    ]);
}

function nominationManagementSubject(Event $event, string $status = 'draft', int $topN = 2): VotingSubject
{
    $subject = $event->votingSubjects()->create([
        'slug' => hash('sha256', Str::uuid()),
        'title' => 'Best Teammate',
        'mode' => 'nomination',
        'top_n' => $topN,
        'status' => $status,
    ]);
    foreach (['Ava', 'Bea', 'Cam', 'Dee'] as $order => $name) {
        $registration = nominationManagementRegistration($event, $name);
        $subject->contestants()->create([
            'name' => $name.' Lee',
            'attendee_id' => $registration->attendee_id,
            'display_order' => $order,
        ]);
    }

    return $subject;
}

function nominationManagementVotes(VotingSubject $subject, array $counts): void
{
    foreach ($subject->contestants()->orderBy('display_order')->get() as $index => $contestant) {
        for ($i = 0; $i < $counts[$index]; $i++) {
            $registration = nominationManagementRegistration($subject->event, 'Voter');
            $subject->votes()->create([
                'registration_id' => $registration->id,
                'voting_contestant_id' => $contestant->id,
            ]);
        }
    }
}

test('owner creates a draft nomination from title and top N only with no preselected contestants', function () {
    $event = nominationManagementEvent();

    $created = $this->actingAs(User::find($event->created_by))
        ->postJson("/api/events/{$event->slug}/nominations", [
            'title' => '  Best Teammate  ',
            'top_n' => 8,
        ])->assertCreated()->assertJsonPath('data.title', 'Best Teammate')
        ->assertJsonPath('data.mode', 'nomination')->assertJsonPath('data.top_n', 8)
        ->assertJsonPath('data.status', 'draft')->assertJsonPath('data.generated_subject', null)
        ->assertJsonPath('data.generated_from_subject_id', null)
        ->assertJsonCount(0, 'data.contestants');

    expect($created->json('data.slug'))->toMatch('/^[a-f0-9]{64}$/');
    expect($created->json('data.public_url'))->toMatch('#/nomination/[a-f0-9]{64}$#');
    $this->getJson('/api/voting/'.$created->json('data.slug'))->assertNotFound();
    $this->assertDatabaseCount('voting_subjects', 1);
    $this->assertDatabaseCount('voting_contestants', 0);
});

test('complete attendee names exceeding 255 characters persist through nomination creation and finalist generation', function () {
    $event = nominationManagementEvent();
    $firstName = str_repeat('陳', 255);
    $lastName = str_repeat('林', 255);
    $completeName = $firstName.' '.$lastName;
    $registration = nominationManagementRegistration($event, $firstName);
    $registration->attendee->update(['last_name' => $lastName]);
    $base = "/api/events/{$event->slug}/nominations";

    $created = $this->actingAs(User::find($event->created_by))->postJson($base, [
        'title' => 'Long Name Award', 'top_n' => 2,
    ])->assertCreated()->assertJsonCount(0, 'data.contestants');
    $subject = VotingSubject::where('slug', $created->json('data.slug'))->firstOrFail();
    $this->postJson("{$base}/{$subject->slug}/open")->assertOk();
    foreach ([$registration, nominationManagementRegistration($event, 'Bea')] as $nominee) {
        $this->postJson("/api/nominations/{$subject->slug}/submit", [
            'attendee_id' => $nominee->attendee_id,
            'registration_code' => $nominee->registration_code,
        ])->assertCreated();
    }
    expect(mb_strlen($subject->contestants()->first()->name))->toBe(511);
    $this->postJson("{$base}/{$subject->slug}/close")->assertOk()
        ->assertJsonPath('data.generated_subject.contestants.0.name', $completeName);
    expect($subject->fresh()->generatedSubject->contestants()->first()->name)->toBe($completeName);

    // SQLite accepts oversized VARCHAR values; also verify the deployed storage contract.
    expect(Schema::getColumnType('voting_contestants', 'name'))->toBe('text');

    $migration = require database_path('migrations/2026_10_23_010000_expand_voting_contestant_names.php');
    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'Cannot narrow contestant names while names exceed 255 characters.');
    expect($subject->fresh()->generatedSubject->contestants()->first()->name)->toBe($completeName)
        ->and(Schema::getColumnType('voting_contestants', 'name'))->toBe('text');
});

test('nomination creation rejects top N beyond unsigned integer storage', function () {
    $event = nominationManagementEvent();
    $this->actingAs(User::find($event->created_by))->postJson("/api/events/{$event->slug}/nominations", [
        'title' => 'Overflow', 'top_n' => 4294967296,
    ])->assertUnprocessable()->assertJsonValidationErrors('top_n');
    $this->assertDatabaseCount('voting_subjects', 0);
});

test('draft edits reject top N beyond unsigned integer storage without changing the nomination', function () {
    $event = nominationManagementEvent();
    $subject = nominationManagementSubject($event);
    $this->actingAs(User::find($event->created_by))
        ->patchJson("/api/events/{$event->slug}/nominations/{$subject->slug}", [
            'title' => 'Overflow', 'top_n' => 4294967296,
        ])->assertUnprocessable()->assertJsonValidationErrors('top_n');
    expect($subject->fresh()->top_n)->toBe(2)->and($subject->fresh()->title)->toBe('Best Teammate')
        ->and($subject->contestants()->count())->toBe(4);
});

test('maximum stored top N remains valid and advances every nominee when it exceeds the nominee count', function () {
    $event = nominationManagementEvent();
    $first = nominationManagementRegistration($event, 'Ava');
    $second = nominationManagementRegistration($event, 'Bea');
    $base = "/api/events/{$event->slug}/nominations";
    $created = $this->actingAs(User::find($event->created_by))->postJson($base, [
        'title' => 'Maximum N', 'top_n' => 4294967295,
    ])->assertCreated()->assertJsonPath('data.top_n', 4294967295);
    $subject = VotingSubject::where('slug', $created->json('data.slug'))->firstOrFail();
    $this->patchJson("{$base}/{$subject->slug}", [
        'title' => 'Maximum N', 'top_n' => 4294967295,
    ])->assertOk()->assertJsonPath('data.top_n', 4294967295);
    $this->postJson("{$base}/{$subject->slug}/open")->assertOk();
    foreach ([$first, $second] as $nominee) {
        $this->postJson("/api/nominations/{$subject->slug}/submit", [
            'registration_code' => $nominee->registration_code,
            'attendee_id' => $nominee->attendee_id,
        ])->assertCreated();
    }
    $this->postJson("{$base}/{$subject->slug}/close")->assertOk()
        ->assertJsonCount(2, 'data.generated_subject.contestants');
});

test('nomination creation validates title and top N only and rejects the obsolete nominee list', function () {
    $event = nominationManagementEvent();
    $valid = ['title' => 'Award', 'top_n' => 2];
    $this->actingAs(User::find($event->created_by));

    foreach ([
        [[], ['title', 'top_n']],
        [['title' => '   '], ['title']],
        [['title' => str_repeat('a', 256)], ['title']],
        [['top_n' => 1], ['top_n']],
        [['top_n' => 2.5], ['top_n']],
        [['attendee_ids' => [1, 2]], ['attendee_ids']],
    ] as [$overrides, $errors]) {
        $this->postJson("/api/events/{$event->slug}/nominations", $overrides === [] ? [] : array_replace($valid, $overrides))
            ->assertUnprocessable()->assertJsonValidationErrors($errors);
    }
    $this->assertDatabaseCount('voting_subjects', 0);
});


test('draft edits only update title and top N and preserve nominees already submitted', function () {
    $event = nominationManagementEvent();
    $subject = nominationManagementSubject($event);
    $oldIds = $subject->contestants()->pluck('id');
    $url = "/api/events/{$event->slug}/nominations/{$subject->slug}";
    $this->actingAs(User::find($event->created_by))->patchJson($url, [
        'title' => ' Revised ', 'top_n' => 3,
    ])->assertOk()->assertJsonPath('data.title', 'Revised')->assertJsonPath('data.top_n', 3)
        ->assertJsonCount(4, 'data.contestants');

    $this->patchJson($url, ['title' => 'Invalid', 'top_n' => 1])
        ->assertUnprocessable()->assertJsonValidationErrors('top_n');
    $this->patchJson($url, ['title' => 'Invalid', 'top_n' => 4, 'attendee_ids' => [1, 2]])
        ->assertUnprocessable()->assertJsonValidationErrors('attendee_ids');
    expect($subject->fresh()->title)->toBe('Revised')->and($subject->fresh()->top_n)->toBe(3);
    expect(VotingContestant::whereIn('id', $oldIds)->count())->toBe(4);
});

test('nomination management requires authentication and the owning admin', function () {
    $event = nominationManagementEvent();
    $subject = nominationManagementSubject($event);
    $base = "/api/events/{$event->slug}/nominations";
    $payload = ['title' => 'Changed', 'top_n' => 2];

    foreach ([[null, 401], [User::factory()->scanner()->create(), 403], [User::factory()->create(), 404]] as [$user, $status]) {
        if ($user) {
            $this->actingAs($user);
        }
        $this->postJson($base, $payload)->assertStatus($status);
        $this->patchJson("{$base}/{$subject->slug}", $payload)->assertStatus($status);
        $this->postJson("{$base}/{$subject->slug}/open")->assertStatus($status);
        $this->postJson("{$base}/{$subject->slug}/close")->assertStatus($status);
    }
    expect($subject->fresh()->status)->toBe('draft')->and($subject->fresh()->title)->toBe('Best Teammate');
    $this->assertDatabaseCount('voting_subjects', 1);
});

test('nomination routes hide subjects in another event and manual subjects', function () {
    $owner = User::factory()->create();
    $event = nominationManagementEvent($owner);
    $other = nominationManagementSubject(nominationManagementEvent($owner));
    $manual = $event->votingSubjects()->create(['slug' => hash('sha256', 'manual'), 'title' => 'Manual']);
    $this->actingAs($owner);
    foreach ([$other, $manual] as $subject) {
        $url = "/api/events/{$event->slug}/nominations/{$subject->slug}";
        $this->patchJson($url, [])->assertNotFound();
        $this->postJson("{$url}/open")->assertNotFound();
        $this->postJson("{$url}/close")->assertNotFound();
    }
});

test('opening a draft nomination exposes the unchanged public ballot and prevents further edits or reopening', function () {
    $event = nominationManagementEvent();
    $subject = nominationManagementSubject($event);
    $url = "/api/events/{$event->slug}/nominations/{$subject->slug}";
    $this->actingAs(User::find($event->created_by))->postJson("{$url}/open")
        ->assertOk()->assertJsonPath('data.status', 'active');
    $this->getJson("/api/voting/{$subject->slug}")->assertNotFound();
    $this->getJson("/api/nominations/{$subject->slug}")->assertOk()->assertExactJson([
        'data' => ['title' => 'Best Teammate', 'status' => 'active'],
    ]);
    $this->postJson("{$url}/open")->assertStatus(409);
    $this->patchJson($url, [])->assertStatus(409);
    expect($subject->fresh()->top_n)->toBe(2)->and($subject->contestants()->count())->toBe(4);
});

test('opening a nomination does not require preselected contestants', function () {
    $event = nominationManagementEvent();
    $subject = $event->votingSubjects()->create([
        'slug' => hash('sha256', Str::uuid()), 'title' => 'Best Teammate',
        'mode' => 'nomination', 'top_n' => 2, 'status' => 'draft',
    ]);
    $this->actingAs(User::find($event->created_by))
        ->postJson("/api/events/{$event->slug}/nominations/{$subject->slug}/open")
        ->assertOk()->assertJsonPath('data.status', 'active')->assertJsonCount(0, 'data.contestants');
});

test('nomination submission accepts one confirmed event registration code without check in', function () {
    $event = nominationManagementEvent();
    $subject = nominationManagementSubject($event);
    $this->actingAs(User::find($event->created_by))
        ->postJson("/api/events/{$event->slug}/nominations/{$subject->slug}/open")->assertOk();
    $registration = nominationManagementRegistration($event, 'Voter');
    $candidate = $subject->contestants()->first()->attendee_id;
    $payload = ['registration_code' => '  '.strtolower($registration->registration_code).'  ', 'attendee_id' => $candidate];
    $url = "/api/nominations/{$subject->slug}/submit";
    $this->postJson($url, $payload)->assertCreated();
    $this->postJson($url, $payload)->assertStatus(409);
    foreach ([nominationManagementRegistration($event, 'Cancelled', 'cancelled'),
        nominationManagementRegistration($event, 'Pending', 'pending'),
        nominationManagementRegistration(nominationManagementEvent(), 'Other')] as $invalid) {
        $this->postJson($url, [...$payload, 'registration_code' => $invalid->registration_code])->assertUnprocessable();
    }
    expect($subject->votes()->count())->toBe(1);
    $this->assertDatabaseCount('check_ins', 0);
});

test('closing ranks nominees by votes includes the cutoff tie and creates one linked same title manual draft', function () {
    $event = nominationManagementEvent();
    $subject = nominationManagementSubject($event, 'active');
    nominationManagementVotes($subject, [1, 3, 1, 0]);
    $this->actingAs(User::find($event->created_by))
        ->postJson("/api/events/{$event->slug}/nominations/{$subject->slug}/close")
        ->assertOk()->assertJsonPath('data.status', 'closed')
        ->assertJsonPath('data.generated_subject.title', 'Best Teammate')
        ->assertJsonPath('data.generated_subject.mode', 'manual')
        ->assertJsonPath('data.generated_subject.status', 'draft')
        ->assertJsonPath('data.generated_subject.top_n', null)
        ->assertJsonPath('data.generated_subject.generated_from_subject_id', $subject->id)
        ->assertJsonCount(3, 'data.generated_subject.contestants')
        ->assertJsonPath('data.generated_subject.contestants.0.name', 'Bea Lee')
        ->assertJsonPath('data.generated_subject.contestants.1.name', 'Ava Lee')
        ->assertJsonPath('data.generated_subject.contestants.2.name', 'Cam Lee');

    $generated = $subject->fresh()->generatedSubject;
    expect($generated->slug)->not->toBe($subject->slug)
        ->and($generated->contestants()->orderBy('display_order')->pluck('display_order')->all())->toBe([0, 1, 2])
        ->and($subject->votes()->count())->toBe(5);
    $this->getJson("/api/voting/{$subject->slug}")->assertNotFound();
    $this->getJson("/api/voting/{$generated->slug}")->assertNotFound();
    $this->postJson("/api/voting/{$subject->slug}/votes", [
        'registration_code' => $subject->votes()->first()->registration->registration_code,
        'contestant_id' => $subject->contestants()->first()->id,
    ])->assertNotFound();
});

test('N beyond the nominee count advances all nominees including those with zero votes', function () {
    $event = nominationManagementEvent();
    $subject = nominationManagementSubject($event, 'active', 20);
    nominationManagementVotes($subject, [0, 0, 1, 0]);
    $this->actingAs(User::find($event->created_by))
        ->postJson("/api/events/{$event->slug}/nominations/{$subject->slug}/close")->assertOk();
    expect($subject->fresh()->generatedSubject->contestants()->orderBy('display_order')->pluck('name')->all())
        ->toBe(['Cam Lee', 'Ava Lee', 'Bea Lee', 'Dee Lee']);
});

test('zero votes reject closure without closing or generating a draft', function () {
    $event = nominationManagementEvent();
    $subject = nominationManagementSubject($event, 'active');
    $this->actingAs(User::find($event->created_by))
        ->postJson("/api/events/{$event->slug}/nominations/{$subject->slug}/close")
        ->assertStatus(409)->assertJsonPath('message', 'A nomination with no votes cannot be closed.');
    expect($subject->fresh()->status)->toBe('active')->and($subject->generatedSubject()->exists())->toBeFalse();
    $this->assertDatabaseCount('voting_subjects', 1);
});

test('one unique nominee cannot close into a voting draft that cannot be activated', function () {
    $event = nominationManagementEvent();
    $subject = nominationManagementSubject($event, 'active');
    $subject->contestants()->delete();
    $candidate = nominationManagementRegistration($event, 'Jamie');
    $subject->contestants()->create([
        'name' => 'Jamie Lee',
        'attendee_id' => $candidate->attendee_id,
        'display_order' => 0,
    ]);
    nominationManagementVotes($subject, [1]);

    $this->actingAs(User::find($event->created_by))
        ->postJson("/api/events/{$event->slug}/nominations/{$subject->slug}/close")
        ->assertStatus(409)
        ->assertJsonPath('message', 'At least two unique nominees are required to create a voting draft.');

    expect($subject->fresh()->status)->toBe('active')
        ->and($subject->generatedSubject()->exists())->toBeFalse();
    $this->assertDatabaseCount('voting_subjects', 1);
});

test('draft and already closed nominations reject closure and repeated requests never generate a second draft', function () {
    $event = nominationManagementEvent();
    $subject = nominationManagementSubject($event);
    $url = "/api/events/{$event->slug}/nominations/{$subject->slug}";
    $this->actingAs(User::find($event->created_by))->postJson("{$url}/close")->assertStatus(409);
    $this->postJson("{$url}/open")->assertOk();
    nominationManagementVotes($subject, [2, 1, 0, 0]);
    $first = $this->postJson("{$url}/close")->assertOk()->json('data.generated_subject.id');
    for ($i = 0; $i < 3; $i++) {
        $this->postJson("{$url}/close")->assertStatus(409);
    }
    $this->postJson("{$url}/open")->assertStatus(409);
    $this->patchJson($url, [])->assertStatus(409);
    expect($subject->generatedSubject()->count())->toBe(1)->and($subject->fresh()->generatedSubject->id)->toBe($first);
    $this->assertDatabaseCount('voting_subjects', 2);
});

test('a close request rechecks a round closed by another request after route binding', function () {
    $event = nominationManagementEvent();
    $subject = nominationManagementSubject($event, 'active');
    nominationManagementVotes($subject, [2, 1, 0, 0]);
    $interleaved = false;
    VotingSubject::retrieved(function (VotingSubject $retrieved) use ($subject, $event, &$interleaved): void {
        if (! $interleaved && $retrieved->is($subject)) {
            $interleaved = true;
            app(NominationService::class)->close($event, $retrieved);
        }
    });

    $this->actingAs(User::find($event->created_by))
        ->postJson("/api/events/{$event->slug}/nominations/{$subject->slug}/close")->assertStatus(409);
    expect($interleaved)->toBeTrue()->and($subject->fresh()->status)->toBe('closed')
        ->and($subject->generatedSubject()->count())->toBe(1);
});

test('failed finalist creation rolls back the entire close transaction and can be retried', function () {
    $event = nominationManagementEvent();
    $subject = nominationManagementSubject($event, 'active');
    nominationManagementVotes($subject, [2, 1, 0, 0]);
    $fail = true;
    VotingContestant::creating(function (VotingContestant $contestant) use (&$fail): void {
        if ($fail && $contestant->name === 'Bea Lee') {
            throw new RuntimeException('Simulated finalist persistence failure.');
        }
    });
    $url = "/api/events/{$event->slug}/nominations/{$subject->slug}/close";
    $this->actingAs(User::find($event->created_by))->postJson($url)->assertStatus(500);
    expect($subject->fresh()->status)->toBe('active')->and($subject->generatedSubject()->exists())->toBeFalse();
    $this->assertDatabaseCount('voting_subjects', 1);
    $this->assertDatabaseCount('voting_contestants', 4);
    expect($subject->votes()->count())->toBe(3);
    $fail = false;
    $this->postJson($url)->assertOk();
    expect($subject->generatedSubject()->count())->toBe(1);
});

test('manual management cannot bypass nomination editing opening or atomic closing', function () {
    $event = nominationManagementEvent();
    $subject = nominationManagementSubject($event);
    $url = "/api/events/{$event->slug}/voting-subjects/{$subject->slug}";
    $this->actingAs(User::find($event->created_by))->patchJson($url, [
        'title' => 'Bypass', 'contestants' => [['name' => 'One'], ['name' => 'Two']],
    ])->assertNotFound();
    $this->postJson("{$url}/activate")->assertNotFound();
    $subject->update(['status' => 'active']);
    $this->postJson("{$url}/close")->assertNotFound();
    expect($subject->fresh()->status)->toBe('active')->and($subject->fresh()->title)->toBe('Best Teammate')
        ->and($subject->contestants()->count())->toBe(4);
});

test('admin serializers expose nomination metadata and both directions of generated draft linkage', function () {
    $event = nominationManagementEvent();
    $subject = nominationManagementSubject($event, 'active');
    nominationManagementVotes($subject, [2, 1, 0, 0]);
    $this->actingAs(User::find($event->created_by))
        ->postJson("/api/events/{$event->slug}/nominations/{$subject->slug}/close")->assertOk();
    $generated = $subject->fresh()->generatedSubject;
    $base = "/api/events/{$event->slug}/voting-subjects";
    $this->getJson("{$base}/{$subject->slug}")->assertOk()->assertJsonPath('data.mode', 'nomination')
        ->assertJsonPath('data.top_n', 2)->assertJsonPath('data.generated_subject.id', $generated->id)
        ->assertJsonPath('data.generated_subject.slug', $generated->slug);
    $this->getJson("{$base}/{$generated->slug}")->assertOk()->assertJsonPath('data.mode', 'manual')
        ->assertJsonPath('data.top_n', null)->assertJsonPath('data.generated_from_subject_id', $subject->id);
    $listed = $this->getJson($base)->assertOk()->json('data');
    expect(collect($listed)->firstWhere('id', $subject->id)['generated_subject']['id'])->toBe($generated->id);
});

test('new manual subject responses immediately expose their default mode and empty nomination metadata', function () {
    $event = nominationManagementEvent();
    $this->actingAs(User::find($event->created_by))
        ->postJson("/api/events/{$event->slug}/voting-subjects", [
            'title' => 'Manual Award', 'contestants' => [['name' => 'One'], ['name' => 'Two']],
        ])->assertCreated()->assertJsonPath('data.mode', 'manual')->assertJsonPath('data.top_n', null)
        ->assertJsonPath('data.generated_from_subject_id', null)->assertJsonPath('data.generated_subject', null);
});

test('generated manual drafts can be edited and activated and a nominating voter may vote again', function () {
    $event = nominationManagementEvent();
    $subject = nominationManagementSubject($event, 'active');
    nominationManagementVotes($subject, [2, 1, 0, 0]);
    $voter = $subject->votes()->first()->registration;
    $this->actingAs(User::find($event->created_by))
        ->postJson("/api/events/{$event->slug}/nominations/{$subject->slug}/close")->assertOk();
    $generated = $subject->fresh()->generatedSubject;
    $base = "/api/events/{$event->slug}/voting-subjects/{$generated->slug}";
    $this->patchJson($base, [
        'title' => 'Reviewed Award', 'contestants' => [['name' => 'Ava Lee'], ['name' => 'Bea Lee']],
    ])->assertOk()->assertJsonPath('data.generated_from_subject_id', $subject->id);
    $this->postJson("{$base}/activate")->assertOk();
    $this->postJson("/api/voting/{$generated->slug}/votes", [
        'registration_code' => $voter->registration_code,
        'contestant_id' => $generated->contestants()->first()->id,
    ])->assertCreated();
    expect($generated->votes()->count())->toBe(1)->and($subject->votes()->count())->toBe(3);
});
