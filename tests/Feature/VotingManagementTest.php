<?php

use App\Models\Attendee;
use App\Models\Event;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;

test('an event persists ordered contestants and one vote per registration per subject', function () {
    $owner = User::factory()->create();
    $event = Event::create([
        'created_by' => $owner->id,
        'title' => 'Annual Gala',
        'slug' => 'annual-gala',
        'starts_at' => now()->addDay(),
        'ends_at' => now()->addDays(2),
    ]);
    $form = $event->registrationForms()->create(['title' => 'Registration']);
    $attendee = Attendee::create([
        'first_name' => 'Jamie',
        'last_name' => 'Rivera',
        'email' => 'jamie@example.com',
        'email_normalized' => 'jamie@example.com',
    ]);
    $registration = $event->registrations()->create([
        'registration_form_id' => $form->id,
        'attendee_id' => $attendee->id,
        'registration_code' => 'REG-VOTE01',
        'registered_at' => now(),
    ]);

    $firstSubject = $event->votingSubjects()->create([
        'slug' => hash('sha256', 'best-performer'),
        'title' => 'Best Performer',
        'status' => 'draft',
    ]);
    $secondSubject = $event->votingSubjects()->create([
        'slug' => hash('sha256', 'best-costume'),
        'title' => 'Best Costume',
        'status' => 'active',
    ]);
    $later = $firstSubject->contestants()->create(['name' => 'Taylor', 'display_order' => 2]);
    $earlier = $firstSubject->contestants()->create(['name' => 'Jordan', 'display_order' => 1]);
    $defaultOrder = $secondSubject->contestants()->create(['name' => 'Sam']);
    $firstVote = $firstSubject->votes()->create([
        'voting_contestant_id' => $earlier->id,
        'registration_id' => $registration->id,
    ]);
    $secondVote = $secondSubject->votes()->create([
        'voting_contestant_id' => $defaultOrder->id,
        'registration_id' => $registration->id,
    ]);

    expect($event->votingSubjects()->orderBy('id')->pluck('id')->all())->toBe([$firstSubject->id, $secondSubject->id])
        ->and($firstSubject->event->is($event))->toBeTrue()
        ->and($firstSubject->getRouteKey())->toBe(hash('sha256', 'best-performer'))
        ->and($firstSubject->contestants()->orderBy('display_order')->pluck('id')->all())->toBe([$earlier->id, $later->id])
        ->and($defaultOrder->fresh()->display_order)->toBe(0)
        ->and($earlier->subject->is($firstSubject))->toBeTrue()
        ->and($firstSubject->votes()->pluck('id')->all())->toBe([$firstVote->id])
        ->and($earlier->votes()->pluck('id')->all())->toBe([$firstVote->id])
        ->and($registration->votingVotes()->orderBy('id')->pluck('id')->all())->toBe([$firstVote->id, $secondVote->id])
        ->and($firstVote->subject->is($firstSubject))->toBeTrue()
        ->and($firstVote->contestant->is($earlier))->toBeTrue()
        ->and($firstVote->registration->is($registration))->toBeTrue();

    expect(fn () => $firstSubject->votes()->create([
        'voting_contestant_id' => $later->id,
        'registration_id' => $registration->id,
    ]))->toThrow(UniqueConstraintViolationException::class);

    $firstSubject->delete();

    $this->assertDatabaseMissing('voting_contestants', ['id' => $earlier->id]);
    $this->assertDatabaseMissing('voting_contestants', ['id' => $later->id]);
    $this->assertDatabaseMissing('voting_votes', ['id' => $firstVote->id]);
    $this->assertDatabaseHas('voting_votes', ['id' => $secondVote->id]);
});

test('a vote rejects a contestant from another subject', function () {
    [$event, $registration] = votingEventWithRegistration('contestant');
    $subject = $event->votingSubjects()->create([
        'slug' => hash('sha256', 'first-subject'),
        'title' => 'First Subject',
    ]);
    $otherSubject = $event->votingSubjects()->create([
        'slug' => hash('sha256', 'second-subject'),
        'title' => 'Second Subject',
    ]);
    $otherContestant = $otherSubject->contestants()->create(['name' => 'Other Contestant']);

    expect(fn () => $subject->votes()->create([
        'voting_contestant_id' => $otherContestant->id,
        'registration_id' => $registration->id,
    ]))->toThrow(QueryException::class);
});

test('a vote rejects a registration from another event', function () {
    [$event] = votingEventWithRegistration('first-event');
    [, $otherRegistration] = votingEventWithRegistration('second-event');
    $subject = $event->votingSubjects()->create([
        'slug' => hash('sha256', 'cross-event-subject'),
        'title' => 'Event Subject',
    ]);
    $contestant = $subject->contestants()->create(['name' => 'First Contestant']);

    expect(fn () => $subject->votes()->create([
        'voting_contestant_id' => $contestant->id,
        'registration_id' => $otherRegistration->id,
    ]))->toThrow(QueryException::class);
});

function votingEventWithRegistration(string $suffix): array
{
    $owner = User::factory()->create();
    $event = Event::create([
        'created_by' => $owner->id,
        'title' => 'Event '.$suffix,
        'slug' => 'event-'.$suffix,
        'starts_at' => now()->addDay(),
        'ends_at' => now()->addDays(2),
    ]);
    $form = $event->registrationForms()->create(['title' => 'Registration']);
    $email = $suffix.'@example.com';
    $attendee = Attendee::create([
        'first_name' => 'Jamie',
        'last_name' => 'Rivera',
        'email' => $email,
        'email_normalized' => $email,
    ]);
    $registration = $event->registrations()->create([
        'registration_form_id' => $form->id,
        'attendee_id' => $attendee->id,
        'registration_code' => 'REG-'.$suffix,
        'registered_at' => now(),
    ]);

    return [$event, $registration];
}

function votingManagementEvent(User $owner, string $slug = 'voting-gala'): Event
{
    return Event::create([
        'created_by' => $owner->id,
        'title' => 'Voting Gala',
        'slug' => $slug,
        'starts_at' => now()->addDay(),
        'ends_at' => now()->addDays(2),
    ]);
}

test('owner creates and reads a subject with an opaque public link and ordered contestants', function () {
    config()->set('app.frontend_url', 'https://front.example/app/');
    $owner = User::factory()->create();
    $event = votingManagementEvent($owner);
    $url = "/api/events/{$event->slug}/voting-subjects";

    $created = $this->actingAs($owner)->postJson($url, [
        'title' => '  Best Performer  ',
        'contestants' => [['name' => '  Taylor  '], ['name' => ' Jordan ']],
    ])->assertCreated()->assertJsonPath('data.title', 'Best Performer')
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.contestant_count', 2)
        ->assertJsonPath('data.total_votes', 0)
        ->assertJsonPath('data.contestants.0.name', 'Taylor')
        ->assertJsonPath('data.contestants.1.name', 'Jordan');
    $slug = $created->json('data.slug');
    expect($slug)->toMatch('/^[a-f0-9]{64}$/')
        ->and($created->json('data.public_url'))->toBe("https://front.example/app/vote/{$slug}");

    $this->getJson($url)->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.slug', $slug)->assertJsonPath('data.0.contestant_count', 2)
        ->assertJsonPath('data.0.total_votes', 0);
    $this->getJson("{$url}/{$slug}")->assertOk()
        ->assertJsonPath('data.public_url', "https://front.example/app/vote/{$slug}")
        ->assertJsonPath('data.contestants.0.display_order', 0)
        ->assertJsonPath('data.contestants.1.display_order', 1);
    $this->assertDatabaseHas('voting_subjects', ['event_id' => $event->id, 'slug' => $slug, 'title' => 'Best Performer']);
});

test('scanner and another admin cannot access any subject management endpoint', function () {
    $owner = User::factory()->create();
    $scanner = User::factory()->scanner()->create();
    $otherAdmin = User::factory()->create();
    $event = votingManagementEvent($owner);
    $subject = $event->votingSubjects()->create(['slug' => hash('sha256', 'authorization'), 'title' => 'Award']);
    $subject->contestants()->createMany([['name' => 'One', 'display_order' => 0], ['name' => 'Two', 'display_order' => 1]]);
    $url = "/api/events/{$event->slug}/voting-subjects";
    $payload = ['title' => 'Award', 'contestants' => [['name' => 'One'], ['name' => 'Two']]];

    foreach ([$scanner, $otherAdmin] as $user) {
        $expected = $user->isScanner() ? 403 : 404;
        $this->actingAs($user)->getJson($url)->assertStatus($expected);
        $this->postJson($url, $payload)->assertStatus($expected);
        $this->getJson("{$url}/{$subject->slug}")->assertStatus($expected);
        $this->patchJson("{$url}/{$subject->slug}", $payload)->assertStatus($expected);
        $this->deleteJson("{$url}/{$subject->slug}")->assertStatus($expected);
        $this->postJson("{$url}/{$subject->slug}/activate")->assertStatus($expected);
        $this->postJson("{$url}/{$subject->slug}/close")->assertStatus($expected);
    }
    $this->assertDatabaseCount('voting_subjects', 1);
    $this->assertDatabaseHas('voting_subjects', ['id' => $subject->id, 'status' => 'draft']);
});

test('a subject from another event is hidden on every subject route', function () {
    $owner = User::factory()->create();
    $event = votingManagementEvent($owner);
    $otherEvent = votingManagementEvent($owner, 'other-voting-gala');
    $subject = $otherEvent->votingSubjects()->create(['slug' => hash('sha256', 'other-event'), 'title' => 'Other Award']);
    $url = "/api/events/{$event->slug}/voting-subjects/{$subject->slug}";
    $payload = ['title' => 'Changed', 'contestants' => [['name' => 'One'], ['name' => 'Two']]];

    $this->actingAs($owner)->getJson($url)->assertNotFound();
    $this->patchJson($url, $payload)->assertNotFound();
    $this->deleteJson($url)->assertNotFound();
    $this->postJson("{$url}/activate")->assertNotFound();
    $this->postJson("{$url}/close")->assertNotFound();
    $this->assertDatabaseHas('voting_subjects', ['id' => $subject->id, 'title' => 'Other Award']);
});

test('subject creation validates required fields, trimmed names, uniqueness, and length limits', function () {
    $owner = User::factory()->create();
    $event = votingManagementEvent($owner);
    $url = "/api/events/{$event->slug}/voting-subjects";
    $this->actingAs($owner);

    foreach ([
        [[], ['title', 'contestants']],
        [['title' => '   ', 'contestants' => [['name' => 'One'], ['name' => 'Two']]], ['title']],
        [['title' => str_repeat('A', 256), 'contestants' => [['name' => 'One'], ['name' => 'Two']]], ['title']],
        [['title' => 'Award', 'contestants' => 'invalid'], ['contestants']],
        [['title' => 'Award', 'contestants' => [['name' => 'One']]], ['contestants']],
        [['title' => 'Award', 'contestants' => [['name' => 'One'], ['name' => ' one ']]], ['contestants.1.name']],
        [['title' => 'Award', 'contestants' => [['name' => '  '], ['name' => 'Two']]], ['contestants.0.name']],
        [['title' => 'Award', 'contestants' => [['name' => str_repeat('X', 256)], ['name' => 'Two']]], ['contestants.0.name']],
        [['title' => 'Award', 'contestants' => array_fill(0, 251, ['name' => 'Repeated'])], ['contestants']],
    ] as [$payload, $errors]) {
        $this->postJson($url, $payload)->assertUnprocessable()->assertJsonValidationErrors($errors);
    }
    $this->assertDatabaseCount('voting_subjects', 0);
});

test('draft update replaces contestants in the submitted order and validates the replacement', function () {
    $owner = User::factory()->create();
    $event = votingManagementEvent($owner);
    $subject = $event->votingSubjects()->create(['slug' => hash('sha256', 'replace'), 'title' => 'Old Award']);
    $old = $subject->contestants()->create(['name' => 'Old', 'display_order' => 0]);
    $url = "/api/events/{$event->slug}/voting-subjects/{$subject->slug}";
    $this->actingAs($owner)->patchJson($url, [
        'title' => '  New Award ',
        'contestants' => [['name' => ' Second '], ['name' => 'First'], ['name' => 'Third']],
    ])->assertOk()->assertJsonPath('data.title', 'New Award')
        ->assertJsonPath('data.contestants.0.name', 'Second')
        ->assertJsonPath('data.contestants.1.name', 'First')
        ->assertJsonPath('data.contestants.2.name', 'Third')
        ->assertJsonPath('data.contestants.2.display_order', 2);
    $this->assertDatabaseMissing('voting_contestants', ['id' => $old->id]);
    $this->patchJson($url, ['title' => 'Invalid', 'contestants' => [['name' => 'Same'], ['name' => ' same ']]])
        ->assertUnprocessable()->assertJsonValidationErrors(['contestants.1.name']);
    $this->assertDatabaseHas('voting_subjects', ['id' => $subject->id, 'title' => 'New Award']);
    expect($subject->contestants()->orderBy('display_order')->pluck('name')->all())->toBe(['Second', 'First', 'Third']);
});

test('creation rejects keyed contestant input instead of storing client keys as positions', function () {
    $owner = User::factory()->create();
    $event = votingManagementEvent($owner);

    $this->actingAs($owner)->postJson("/api/events/{$event->slug}/voting-subjects", [
        'title' => 'Award',
        'contestants' => [10 => ['name' => 'First'], 2 => ['name' => 'Second']],
    ])->assertUnprocessable()->assertJsonValidationErrors('contestants');
    $this->assertDatabaseCount('voting_subjects', 0);
});

test('draft update rejects keyed contestant input without changing stored order', function () {
    $owner = User::factory()->create();
    $event = votingManagementEvent($owner);
    $subject = $event->votingSubjects()->create(['slug' => hash('sha256', 'keyed-update'), 'title' => 'Award']);
    $subject->contestants()->createMany([
        ['name' => 'First', 'display_order' => 0],
        ['name' => 'Second', 'display_order' => 1],
    ]);

    $this->actingAs($owner)->patchJson("/api/events/{$event->slug}/voting-subjects/{$subject->slug}", [
        'title' => 'Changed',
        'contestants' => [10 => ['name' => 'Second'], 2 => ['name' => 'First']],
    ])->assertUnprocessable()->assertJsonValidationErrors('contestants');
    $this->assertDatabaseHas('voting_subjects', ['id' => $subject->id, 'title' => 'Award']);
    expect($subject->contestants()->orderBy('display_order')->pluck('name')->all())->toBe(['First', 'Second']);
});

test('activation requires two contestants and lifecycle transitions never reopen a subject', function () {
    $owner = User::factory()->create();
    $event = votingManagementEvent($owner);
    $subject = $event->votingSubjects()->create(['slug' => hash('sha256', 'lifecycle'), 'title' => 'Award']);
    $subject->contestants()->create(['name' => 'One', 'display_order' => 0]);
    $url = "/api/events/{$event->slug}/voting-subjects/{$subject->slug}";
    $this->actingAs($owner)->postJson("{$url}/activate")->assertStatus(409)
        ->assertJsonPath('message', 'At least two contestants are required to activate voting.');
    $this->postJson("{$url}/close")->assertStatus(409)
        ->assertJsonPath('message', 'Only active voting subjects can be closed.');
    $subject->contestants()->create(['name' => 'Two', 'display_order' => 1]);
    $this->postJson("{$url}/activate")->assertOk()->assertJsonPath('data.status', 'active');
    $this->postJson("{$url}/activate")->assertStatus(409)
        ->assertJsonPath('message', 'Only draft voting subjects can be activated.');
    $this->patchJson($url, ['title' => 'Changed', 'contestants' => [['name' => 'One'], ['name' => 'Two']]])
        ->assertStatus(409)->assertJsonPath('message', 'Only draft voting subjects can be edited.');
    $this->deleteJson($url)->assertStatus(409)
        ->assertJsonPath('message', 'Only draft voting subjects can be deleted.');
    $this->postJson("{$url}/close")->assertOk()->assertJsonPath('data.status', 'closed');
    $this->postJson("{$url}/close")->assertStatus(409)
        ->assertJsonPath('message', 'Only active voting subjects can be closed.');
    $this->postJson("{$url}/activate")->assertStatus(409)
        ->assertJsonPath('message', 'Only draft voting subjects can be activated.');
    $this->patchJson($url, ['title' => 'Changed', 'contestants' => [['name' => 'One'], ['name' => 'Two']]])->assertStatus(409);
    $this->deleteJson($url)->assertStatus(409);
    $this->assertDatabaseHas('voting_subjects', ['id' => $subject->id, 'status' => 'closed', 'title' => 'Award']);
});

test('owner deletes a draft subject and its contestants', function () {
    $owner = User::factory()->create();
    $event = votingManagementEvent($owner);
    $subject = $event->votingSubjects()->create(['slug' => hash('sha256', 'delete-draft'), 'title' => 'Award']);
    $contestant = $subject->contestants()->create(['name' => 'One']);
    $url = "/api/events/{$event->slug}/voting-subjects/{$subject->slug}";

    $this->actingAs($owner)->deleteJson($url)->assertNoContent();
    $this->assertDatabaseMissing('voting_subjects', ['id' => $subject->id]);
    $this->assertDatabaseMissing('voting_contestants', ['id' => $contestant->id]);
});

test('an active subject rejects even an invalid edit as a lifecycle conflict', function () {
    $owner = User::factory()->create();
    $event = votingManagementEvent($owner);
    $subject = $event->votingSubjects()->create([
        'slug' => hash('sha256', 'invalid-active-edit'),
        'title' => 'Award',
        'status' => 'active',
    ]);
    $url = "/api/events/{$event->slug}/voting-subjects/{$subject->slug}";

    $this->actingAs($owner)->patchJson($url, [])->assertStatus(409)
        ->assertJsonPath('message', 'Only draft voting subjects can be edited.');
});
