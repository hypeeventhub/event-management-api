<?php

use App\Models\Attendee;
use App\Models\Event;
use App\Models\User;

test('an event owner manages three ballots while attendees vote and results stay private', function () {
    config()->set('app.frontend_url', 'https://front.example');

    $owner = User::factory()->create();
    $event = Event::create([
        'created_by' => $owner->id,
        'title' => 'Awards Night',
        'slug' => 'awards-night',
        'starts_at' => now()->addDay(),
        'ends_at' => now()->addDays(2),
    ]);
    $form = $event->registrationForms()->create(['title' => 'Registration']);
    foreach (['REG-FIRST', 'REG-SECOND'] as $code) {
        $email = strtolower($code).'@example.com';
        $attendee = Attendee::create([
            'first_name' => 'Jamie',
            'last_name' => 'Rivera',
            'email' => $email,
            'email_normalized' => $email,
        ]);
        $event->registrations()->create([
            'registration_form_id' => $form->id,
            'attendee_id' => $attendee->id,
            'registration_code' => $code,
            'status' => 'confirmed',
            'registered_at' => now(),
        ]);
    }

    $base = "/api/events/{$event->slug}/voting-subjects";
    $this->actingAs($owner);
    $subjects = [];
    foreach (['Best Singer', 'Best Dancer', 'People’s Choice'] as $title) {
        $subjects[] = $this->postJson($base, [
            'title' => $title,
            'contestants' => [['name' => 'Ava'], ['name' => 'Bea']],
        ])->assertCreated()->json('data');
    }
    $this->getJson($base)->assertOk()->assertJsonCount(3, 'data');

    foreach ($subjects as $subject) {
        expect($subject['public_url'])->toBe("https://front.example/vote/{$subject['slug']}");
        $this->postJson("{$base}/{$subject['slug']}/activate")
            ->assertOk()->assertJsonPath('data.status', 'active');
        $this->getJson("/api/voting/{$subject['slug']}")->assertExactJson([
            'title' => $subject['title'],
            'contestants' => [
                ['id' => $subject['contestants'][0]['id'], 'name' => 'Ava'],
                ['id' => $subject['contestants'][1]['id'], 'name' => 'Bea'],
            ],
        ]);
    }

    $first = $subjects[0];
    $second = $subjects[1];
    $firstVoteUrl = "/api/voting/{$first['slug']}/votes";
    $this->postJson($firstVoteUrl, [
        'registration_code' => '  reg-first  ',
        'contestant_id' => $first['contestants'][0]['id'],
    ])->assertCreated();
    $this->postJson($firstVoteUrl, [
        'registration_code' => 'REG-FIRST',
        'contestant_id' => $first['contestants'][1]['id'],
    ])->assertStatus(409);
    $this->postJson("/api/voting/{$second['slug']}/votes", [
        'registration_code' => 'REG-FIRST',
        'contestant_id' => $second['contestants'][1]['id'],
    ])->assertCreated();
    $this->assertDatabaseCount('check_ins', 0);

    $resultUrl = "{$base}/{$first['slug']}/results";
    $this->getJson($resultUrl)->assertOk()
        ->assertJsonPath('total_votes', 1)
        ->assertJsonPath('total_registrations', 2)
        ->assertJsonPath('participation_percentage', 50)
        ->assertJsonPath('contestants.0.id', $first['contestants'][0]['id'])
        ->assertJsonPath('contestants.0.votes', 1);

    $this->actingAs(User::factory()->scanner()->create())
        ->getJson($resultUrl)->assertForbidden();
    $this->actingAs(User::factory()->create())
        ->getJson($resultUrl)->assertNotFound();

    $this->actingAs($owner)->postJson("{$base}/{$first['slug']}/close")
        ->assertOk()->assertJsonPath('data.status', 'closed');
    $this->postJson($firstVoteUrl, [
        'registration_code' => 'REG-SECOND',
        'contestant_id' => $first['contestants'][0]['id'],
    ])->assertNotFound();
    $this->assertDatabaseCount('voting_votes', 2);
});
