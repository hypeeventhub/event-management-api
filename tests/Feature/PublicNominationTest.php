<?php

use App\Models\Attendee;
use App\Models\Event;
use App\Models\Registration;
use App\Models\User;
use App\Models\VotingSubject;
use Illuminate\Support\Str;

function publicNominationEvent(?User $owner = null, string $suffix = 'main'): Event
{
    return Event::create([
        'created_by' => ($owner ?? User::factory()->create())->id,
        'title' => 'Nomination event '.$suffix,
        'slug' => 'public-nomination-'.$suffix.'-'.Str::lower(Str::random(6)),
        'starts_at' => now()->addDay(),
        'ends_at' => now()->addDays(2),
    ]);
}

function publicNominationRegistration(Event $event, string $firstName, string $lastName, string $status = 'confirmed'): Registration
{
    $email = Str::uuid().'@example.com';
    $attendee = Attendee::create([
        'first_name' => $firstName,
        'last_name' => $lastName,
        'email' => $email,
        'email_normalized' => $email,
        'phone' => 'private-phone',
        'company' => 'private-company',
    ]);

    return $event->registrations()->create([
        'registration_form_id' => $event->registrationForms()->firstOrCreate(['title' => 'Registration'])->id,
        'attendee_id' => $attendee->id,
        'registration_code' => 'REG-'.Str::upper(Str::random(16)),
        'status' => $status,
        'registered_at' => now(),
    ]);
}

function publicNominationSubject(Event $event, string $status = 'active'): VotingSubject
{
    return $event->votingSubjects()->create([
        'slug' => hash('sha256', Str::uuid()),
        'title' => 'Best Teammate',
        'mode' => 'nomination',
        'top_n' => 5,
        'status' => $status,
    ]);
}

test('public nomination lookup returns only title and lifecycle status for every nomination state', function () {
    $event = publicNominationEvent();
    $registration = publicNominationRegistration($event, 'Jamie', 'Rivera');
    $subject = publicNominationSubject($event, 'draft');
    $subject->contestants()->create([
        'attendee_id' => $registration->attendee_id,
        'name' => 'Jamie Rivera',
        'display_order' => 0,
    ]);

    foreach (['draft', 'active', 'closed'] as $status) {
        $subject->update(['status' => $status]);
        $this->getJson("/api/nominations/{$subject->slug}")->assertOk()->assertExactJson([
            'data' => ['title' => 'Best Teammate', 'status' => $status],
        ]);
    }

    $manual = $event->votingSubjects()->create([
        'slug' => hash('sha256', 'manual-public-nomination'), 'title' => 'Manual', 'mode' => 'manual', 'status' => 'active',
    ]);
    $this->getJson("/api/nominations/{$manual->slug}")->assertNotFound();
    $this->getJson('/api/nominations/missing-subject')->assertNotFound();
});

test('public nominee search fuzzy-matches event attendees of any status without private attendee fields', function () {
    $event = publicNominationEvent();
    $otherEvent = publicNominationEvent(suffix: 'other');
    $subject = publicNominationSubject($event);
    $target = publicNominationRegistration($event, 'Jeremiah', 'Quintano', 'cancelled');
    publicNominationRegistration($event, 'Other', 'Person', 'pending');
    publicNominationRegistration($otherEvent, 'Jeremiah', 'Quintano');

    $this->getJson("/api/nominations/{$subject->slug}/candidates?q=Jeremaya%20Quintano")
        ->assertOk()->assertExactJson(['data' => [
            ['id' => $target->attendee_id, 'full_name' => 'Jeremiah Quintano'],
        ]]);
    $this->getJson("/api/nominations/{$subject->slug}/candidates?q=Jeremiah%20Quintano@example.com")
        ->assertOk()->assertExactJson(['data' => []]);
});


test('candidate search and nomination submission only work while an actual nomination is active', function () {
    $event = publicNominationEvent();
    $candidate = publicNominationRegistration($event, 'Jamie', 'Rivera');
    $draft = publicNominationSubject($event, 'draft');
    $payload = ['attendee_id' => $candidate->attendee_id, 'registration_code' => $candidate->registration_code];

    $this->getJson("/api/nominations/{$draft->slug}/candidates?q=Jamie")->assertNotFound();
    $this->postJson("/api/nominations/{$draft->slug}/submit", $payload)->assertNotFound();
    $draft->update(['mode' => 'manual', 'status' => 'active']);
    $this->getJson("/api/nominations/{$draft->slug}/candidates?q=Jamie")->assertNotFound();
    $this->postJson("/api/nominations/{$draft->slug}/submit", $payload)->assertNotFound();
});

test('a confirmed same-event registrant may submit one nomination and concurrent nominations reuse the attendee finalist', function () {
    $owner = User::factory()->create();
    $event = publicNominationEvent($owner);
    $subject = publicNominationSubject($event, 'draft');
    $this->actingAs($owner)->postJson("/api/events/{$event->slug}/nominations/{$subject->slug}/open")
        ->assertOk();

    $candidate = publicNominationRegistration($event, 'Jamie', 'Rivera', 'cancelled');
    $firstVoter = publicNominationRegistration($event, 'Alex', 'One');
    $secondVoter = publicNominationRegistration($event, 'Taylor', 'Two');
    $payload = ['attendee_id' => $candidate->attendee_id, 'registration_code' => $firstVoter->registration_code];

    $this->postJson("/api/nominations/{$subject->slug}/submit", $payload)
        ->assertCreated()->assertExactJson(['message' => 'Your nomination has been submitted.']);
    $this->postJson("/api/nominations/{$subject->slug}/submit", $payload)->assertConflict();
    $this->postJson("/api/nominations/{$subject->slug}/submit", [
        'attendee_id' => $candidate->attendee_id,
        'registration_code' => $secondVoter->registration_code,
    ])->assertCreated();

    expect($subject->contestants()->count())->toBe(1)
        ->and($subject->votes()->count())->toBe(2)
        ->and($subject->contestants()->first()->attendee_id)->toBe($candidate->attendee_id);
});

test('nomination submission rejects invalid codes and nominee IDs outside the event', function () {
    $event = publicNominationEvent();
    $subject = publicNominationSubject($event);
    $otherEvent = publicNominationEvent(suffix: 'other');
    $candidate = publicNominationRegistration($event, 'Jamie', 'Rivera');
    $otherCandidate = publicNominationRegistration($otherEvent, 'Other', 'Person');
    $pendingVoter = publicNominationRegistration($event, 'Pending', 'Voter', 'pending');
    $otherVoter = publicNominationRegistration($otherEvent, 'Other', 'Voter');

    foreach (['not-a-code', $pendingVoter->registration_code, $otherVoter->registration_code] as $code) {
        $this->postJson("/api/nominations/{$subject->slug}/submit", [
            'attendee_id' => $candidate->attendee_id,
            'registration_code' => $code,
        ])->assertUnprocessable();
    }

    $confirmed = publicNominationRegistration($event, 'Confirmed', 'Voter');
    $this->postJson("/api/nominations/{$subject->slug}/submit", [
        'attendee_id' => $otherCandidate->attendee_id,
        'registration_code' => $confirmed->registration_code,
    ])->assertUnprocessable();
    $this->postJson("/api/nominations/{$subject->slug}/submit", [
        'attendee_id' => 'invalid',
        'registration_code' => $confirmed->registration_code,
    ])->assertUnprocessable()->assertJsonValidationErrors('attendee_id');
    expect($subject->votes()->count())->toBe(0)->and($subject->contestants()->count())->toBe(0);
});
