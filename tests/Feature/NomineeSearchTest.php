<?php

use App\Models\Attendee;
use App\Models\Event;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

function nomineeSearchEvent(User $owner, string $slug = 'nominee-search'): Event
{
    return Event::create([
        'created_by' => $owner->id,
        'title' => 'Awards Night',
        'slug' => $slug,
        'starts_at' => now()->addDay(),
        'ends_at' => now()->addDays(2),
    ]);
}

function nomineeSearchRegistration(Event $event, string $firstName, string $lastName, string $status = 'confirmed', ?Attendee $attendee = null): Attendee
{
    $form = $event->registrationForms()->firstOrCreate(['title' => 'Registration']);
    $email = fake()->unique()->safeEmail();
    $attendee ??= Attendee::create([
        'first_name' => $firstName,
        'last_name' => $lastName,
        'email' => $email,
        'email_normalized' => $email,
        'phone' => 'private-phone',
        'company' => 'private-company',
    ]);
    $event->registrations()->create([
        'registration_form_id' => $form->id,
        'attendee_id' => $attendee->id,
        'registration_code' => fake()->unique()->uuid(),
        'status' => $status,
        'registered_at' => now(),
    ]);

    return $attendee;
}

function nomineeSearchUrl(Event $event, string $query = ''): string
{
    return "/api/events/{$event->slug}/nomination-candidates?".http_build_query(['q' => $query]);
}

test('nominee search ranks normalized exact and partial names before fuzzy names', function () {
    $owner = User::factory()->create();
    $event = nomineeSearchEvent($owner);
    $fuzzy = nomineeSearchRegistration($event, 'Jeremiah', 'Quintano');
    $partial = nomineeSearchRegistration($event, 'Jeremaya', 'Quintano Jr');
    $exact = nomineeSearchRegistration($event, 'Jeremaya', 'Quintano');
    nomineeSearchRegistration($event, 'Unrelated', 'Person');

    $this->actingAs($owner)->getJson(nomineeSearchUrl($event, '  JEREMAYA   quintano  '))
        ->assertOk()->assertExactJson(['data' => [
            ['id' => $exact->id, 'full_name' => 'Jeremaya Quintano'],
            ['id' => $partial->id, 'full_name' => 'Jeremaya Quintano Jr'],
            ['id' => $fuzzy->id, 'full_name' => 'Jeremiah Quintano'],
        ]]);
    $this->getJson(nomineeSearchUrl($event, 'quintano'))->assertOk()->assertJsonCount(3, 'data');
});

test('nominee search deduplicates attendees across registrations and includes every registration status', function () {
    // Simulate legacy duplicate registrations; current writes enforce uniqueness.
    Schema::table('registrations', fn (Blueprint $table) => $table->dropUnique(['event_id', 'attendee_id']));
    $owner = User::factory()->create();
    $event = nomineeSearchEvent($owner);
    $confirmed = nomineeSearchRegistration($event, 'Candidate', 'Confirmed');
    nomineeSearchRegistration($event, '', '', 'cancelled', $confirmed);
    $pending = nomineeSearchRegistration($event, 'Candidate', 'Pending', 'pending');
    $cancelled = nomineeSearchRegistration($event, 'Candidate', 'Cancelled', 'cancelled');
    $rejected = nomineeSearchRegistration($event, 'Candidate', 'Rejected', 'rejected');

    $response = $this->actingAs($owner)->getJson(nomineeSearchUrl($event, 'Candidate'))
        ->assertOk()->assertJsonCount(4, 'data');
    expect(array_column($response->json('data'), 'id'))->toEqualCanonicalizing([
        $confirmed->id, $pending->id, $cancelled->id, $rejected->id,
    ]);
});

test('nominee search is scoped to the event and exposes only attendee ids and names', function () {
    $owner = User::factory()->create();
    $event = nomineeSearchEvent($owner);
    $otherEvent = nomineeSearchEvent($owner, 'other-nominee-search');
    $candidate = nomineeSearchRegistration($event, 'Jamie', 'Rivera');
    nomineeSearchRegistration($otherEvent, 'Jamie', 'Rivera');
    Attendee::create([
        'first_name' => 'Jamie', 'last_name' => 'Rivera',
        'email' => 'unregistered@example.com', 'email_normalized' => 'unregistered@example.com',
    ]);

    $this->actingAs($owner)->getJson(nomineeSearchUrl($event, 'Jamie Rivera'))
        ->assertOk()->assertExactJson(['data' => [
            ['id' => $candidate->id, 'full_name' => 'Jamie Rivera'],
        ]]);
    $this->getJson(nomineeSearchUrl($event, $candidate->email))->assertOk()->assertExactJson(['data' => []]);
    $code = $candidate->registrations()->first()->registration_code;
    $this->getJson(nomineeSearchUrl($event, $code))->assertOk()->assertExactJson(['data' => []]);
});

test('nominee search returns an empty array for absent blank or unmatched queries', function () {
    $owner = User::factory()->create();
    $event = nomineeSearchEvent($owner);
    nomineeSearchRegistration($event, 'Jeremiah', 'Quintano');
    $this->actingAs($owner)->getJson("/api/events/{$event->slug}/nomination-candidates")
        ->assertOk()->assertExactJson(['data' => []]);
    foreach (['', '   ', 'zzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzz'] as $query) {
        $this->getJson(nomineeSearchUrl($event, $query))->assertOk()->assertExactJson(['data' => []]);
    }
});

test('nominee search caps distinct results at twenty after ranking', function () {
    $owner = User::factory()->create();
    $event = nomineeSearchEvent($owner);
    for ($i = 0; $i < 25; $i++) {
        nomineeSearchRegistration($event, 'Jamie', 'Rivera '.$i);
    }
    $exact = nomineeSearchRegistration($event, 'Jamie', 'Rivera');

    $response = $this->actingAs($owner)->getJson(nomineeSearchUrl($event, 'Jamie Rivera'))
        ->assertOk()->assertJsonCount(20, 'data')->assertJsonPath('data.0.id', $exact->id);
    expect(array_unique(array_column($response->json('data'), 'id')))->toHaveCount(20);
});

test('nominee search requires an authenticated admin who owns the event', function () {
    $owner = User::factory()->create();
    $event = nomineeSearchEvent($owner);
    $url = nomineeSearchUrl($event, 'Jamie');

    $this->getJson($url)->assertUnauthorized();
    $this->actingAs(User::factory()->scanner()->create())->getJson($url)->assertForbidden();
    $this->actingAs(User::factory()->create())->getJson($url)->assertNotFound();
    $this->actingAs($owner)->getJson('/api/events/missing-event/nomination-candidates?q=Jamie')->assertNotFound();
});

test('nominee search rejects non string queries', function () {
    $owner = User::factory()->create();
    $event = nomineeSearchEvent($owner);

    $this->actingAs($owner)->getJson("/api/events/{$event->slug}/nomination-candidates?q[]=Jamie")
        ->assertUnprocessable()->assertJsonValidationErrors('q');
});
