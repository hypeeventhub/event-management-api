<?php

use App\Mail\EventInvitation;
use App\Mail\RegistrationConfirmation;
use App\Models\Attendee;
use App\Models\EmailInvitation;
use App\Models\Event;
use App\Models\Registration;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

function eventPayload(): array
{
    return [
        'title' => 'Annual Corporate Gala 2026',
        'description' => 'Company celebration.',
        'category' => 'Corporate Gala',
        'capacity' => 180,
        'venue' => 'Skyline Lounge',
        'starts_at' => now()->addWeek()->toIso8601String(),
        'ends_at' => now()->addWeek()->addHours(4)->toIso8601String(),
        'timezone' => 'Asia/Manila',
        'status' => 'published',
        'registration_form' => [
            [
                'key' => 'last-name',
                'system_key' => 'last_name',
                'type' => 'short',
                'label' => 'Last name',
                'description' => null,
                'required' => true,
                'options' => [],
            ],
            [
                'key' => 'first-name',
                'system_key' => 'first_name',
                'type' => 'short',
                'label' => 'First name',
                'description' => null,
                'required' => true,
                'options' => [],
            ],
            [
                'key' => 'work-email',
                'system_key' => 'email',
                'type' => 'short',
                'label' => 'Work email',
                'description' => null,
                'required' => true,
                'options' => [],
            ],
            [
                'key' => 'meal',
                'system_key' => null,
                'type' => 'dropdown',
                'label' => 'Meal preference',
                'description' => null,
                'required' => true,
                'options' => ['Regular', 'Vegetarian'],
            ],
        ],
    ];
}

function registrationAnswerEditingFixture($test, User $owner): array
{
    $eventId = $test->actingAs($owner)
        ->postJson('/api/events', eventPayload())
        ->assertCreated()
        ->json('data.id');
    $event = Event::findOrFail($eventId);
    $form = $event->activeRegistrationForm()->firstOrFail();

    $customFields = [
        [
            'key' => 'attendee-story',
            'type' => 'paragraph',
            'label' => 'Tell us about yourself',
            'is_required' => false,
            'options' => [],
        ],
        [
            'key' => 'visit-date',
            'type' => 'date',
            'label' => 'Visit date',
            'is_required' => false,
            'options' => [],
        ],
        [
            'key' => 'sessions',
            'type' => 'checkboxes',
            'label' => 'Sessions',
            'is_required' => false,
            'options' => [
                ['label' => 'Morning', 'value' => 'morning-1'],
                ['label' => 'Evening', 'value' => 'evening-2'],
            ],
        ],
    ];

    foreach ($customFields as $position => $fieldData) {
        $options = $fieldData['options'];
        unset($fieldData['options']);
        $field = $form->fields()->create([
            ...$fieldData,
            'registration_form_id' => $form->id,
            'system_key' => null,
            'description' => null,
            'placeholder' => null,
            'position' => 4 + $position,
        ]);

        foreach ($options as $optionPosition => $option) {
            $field->options()->create([
                ...$option,
                'position' => $optionPosition,
            ]);
        }
    }

    $email = "jeremiah.{$owner->id}@example.com";
    $attendee = Attendee::create([
        'first_name' => 'Jeremiah',
        'last_name' => 'Quintano',
        'email' => $email,
        'email_normalized' => $email,
    ]);
    $registration = $event->registrations()->create([
        'registration_form_id' => $form->id,
        'attendee_id' => $attendee->id,
        'registration_code' => 'REG-ANSWERS-'.$owner->id,
        'status' => 'confirmed',
        'source' => 'public_form',
        'registered_at' => now(),
        'confirmed_at' => now(),
    ]);
    $answerValues = [
        'last-name' => 'Quintano',
        'first-name' => 'Jeremiah',
        'work-email' => $email,
        'meal' => 'regular-1',
        'attendee-story' => 'Original answer',
        'visit-date' => '2026-11-09',
        'sessions' => ['morning-1'],
    ];

    foreach ($form->fields()->get() as $field) {
        $registration->answers()->create([
            'form_field_id' => $field->id,
            'answer' => $answerValues[$field->key] ?? null,
        ]);
    }

    return [
        'event' => $event,
        'form' => $form,
        'registration' => $registration,
        'attendee' => $attendee,
        'fields' => $form->fields()->with('options')->get()->keyBy('key'),
    ];
}

test('an authenticated user can publish an event with a registration form', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson('/api/events', eventPayload());

    $response
        ->assertCreated()
        ->assertJsonPath('data.title', 'Annual Corporate Gala 2026')
        ->assertJsonPath('data.active_registration_form.fields.0.system_key', 'last_name')
        ->assertJsonPath('data.active_registration_form.fields.1.system_key', 'first_name')
        ->assertJsonCount(4, 'data.active_registration_form.fields');

    $this->assertDatabaseHas('events', ['created_by' => $user->id, 'status' => 'published']);
    $this->assertDatabaseCount('registration_forms', 1);
    $this->assertDatabaseCount('form_fields', 4);
    $this->assertDatabaseCount('field_options', 2);
});

test('event history returns only the owner’s ended events in reverse chronological order with pagination', function () {
    $owner = User::factory()->create();
    $otherOwner = User::factory()->create();
    $createEvent = function (User $creator, string $title, $endsAt, array $extra = []): Event {
        return Event::create([
            'created_by' => $creator->id,
            'title' => $title,
            'slug' => hash('sha256', $title),
            'venue' => $extra['venue'] ?? null,
            'meeting_url' => $extra['meeting_url'] ?? null,
            'capacity' => array_key_exists('capacity', $extra) ? $extra['capacity'] : 200,
            'starts_at' => Carbon::parse($endsAt)->subHours(2),
            'ends_at' => Carbon::parse($endsAt),
            'timezone' => 'Asia/Manila',
            'status' => $extra['status'] ?? 'published',
        ]);
    };

    $older = $createEvent($owner, 'Older Past Event', now()->subDays(3), ['venue' => 'Old Hall', 'capacity' => 150]);
    $newer = $createEvent($owner, 'Recent Past Online Event', now()->subDay(), ['meeting_url' => 'https://meeting.example/private', 'capacity' => null, 'status' => 'cancelled']);
    $createEvent($owner, 'Still Ongoing Event', now()->addHour());
    $createEvent($owner, 'Future Event', now()->addDays(3));
    $createEvent($otherOwner, 'Another Owner Past Event', now()->subDay());

    $this->actingAs($owner)->getJson('/api/events/history?per_page=1&page=1')
        ->assertOk()
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('meta.current_page', 1)
        ->assertJsonPath('meta.last_page', 2)
        ->assertJsonPath('data.0.slug', $newer->slug)
        ->assertJsonPath('data.0.title', 'Recent Past Online Event')
        ->assertJsonPath('data.0.location', 'Online event')
        ->assertJsonPath('data.0.capacity', null)
        ->assertJsonMissing(['meeting_url' => 'https://meeting.example/private']);

    $this->getJson('/api/events/history?per_page=1&page=2')
        ->assertOk()
        ->assertJsonPath('data.0.slug', $older->slug)
        ->assertJsonPath('data.0.location', 'Old Hall')
        ->assertJsonPath('data.0.capacity', 150);

    $this->actingAs(User::factory()->scanner()->create())->getJson('/api/events/history')->assertForbidden();
});

test('an event owner can soft delete an upcoming event while other users cannot', function () {
    $owner = User::factory()->create();
    $otherAdmin = User::factory()->create();
    $eventResponse = $this->actingAs($owner)->postJson('/api/events', eventPayload())->assertCreated();
    $event = Event::findOrFail($eventResponse->json('data.id'));

    $this->actingAs($otherAdmin)->deleteJson("/api/events/{$event->slug}")->assertNotFound();
    $this->actingAs(User::factory()->scanner()->create())->deleteJson("/api/events/{$event->slug}")->assertForbidden();
    $this->assertDatabaseHas('events', ['id' => $event->id, 'deleted_at' => null]);

    $this->actingAs($owner)
        ->deleteJson("/api/events/{$event->slug}")
        ->assertOk()
        ->assertJsonPath('message', 'Event deleted successfully.');

    $this->assertSoftDeleted('events', ['id' => $event->id]);
    $this->actingAs($owner)->getJson('/api/events')->assertOk()->assertJsonCount(0, 'data');
});

test('an admin cannot delete an event after it has started', function () {
    $owner = User::factory()->create();
    $eventResponse = $this->actingAs($owner)->postJson('/api/events', [
        ...eventPayload(),
        'starts_at' => now()->subMinute()->toIso8601String(),
        'ends_at' => now()->addHours(2)->toIso8601String(),
    ])->assertCreated();
    $event = Event::findOrFail($eventResponse->json('data.id'));

    $this->actingAs($owner)
        ->deleteJson("/api/events/{$event->slug}")
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Only upcoming events can be deleted.');

    $this->assertDatabaseHas('events', ['id' => $event->id, 'deleted_at' => null]);
});

test('a guest can register and the owner can retrieve the attendee', function () {
    $user = User::factory()->create();
    $eventResponse = $this->actingAs($user)->postJson('/api/events', eventPayload())->assertCreated();
    $event = Event::findOrFail($eventResponse->json('data.id'));
    $mealValue = $event->activeRegistrationForm()->firstOrFail()
        ->fields()->where('key', 'meal')->firstOrFail()
        ->options()->firstOrFail()->value;

    $registrationResponse = $this->postJson('/api/registration/events/'.$event->slug, [
        'answers' => [
            'first-name' => 'Jamie',
            'last-name' => 'Rivera',
            'work-email' => 'JAMIE@example.com',
            'meal' => $mealValue,
        ],
    ]);

    $registrationResponse
        ->assertOk()
        ->assertExactJson(['email' => 'jamie@example.com']);

    expect(Attendee::count())->toBe(1)
        ->and(Registration::count())->toBe(1);
    $this->assertDatabaseHas('attendees', ['email_normalized' => 'jamie@example.com']);
    $this->assertDatabaseCount('registration_answers', 4);

    $this->actingAs($user)
        ->getJson('/api/events/'.$event->slug.'/registrations')
        ->assertOk()
        ->assertJsonPath('data.0.attendee.first_name', 'Jamie')
        ->assertJsonPath('data.0.attendee.last_name', 'Rivera');
});

test('the attendee list reports whether each registration has voted', function () {
    $user = User::factory()->create();
    $event = Event::findOrFail(
        $this->actingAs($user)->postJson('/api/events', eventPayload())->json('data.id'),
    );
    $mealValue = $event->activeRegistrationForm()->firstOrFail()
        ->fields()->where('key', 'meal')->firstOrFail()
        ->options()->firstOrFail()->value;

    $register = function (string $email) use ($event, $mealValue): int {
        $response = $this->postJson('/api/registration/events/'.$event->slug, [
            'answers' => [
                'first-name' => 'Jamie',
                'last-name' => 'Rivera',
                'work-email' => $email,
                'meal' => $mealValue,
            ],
        ])->assertOk()->assertExactJson(['email' => $email]);

        return (int) Registration::query()
            ->whereHas('attendee', fn ($query) => $query->where('email', $email))
            ->value('id');
    };

    $votedRegistrationId = $register('voted@example.com');
    $notVotedRegistrationId = $register('not-voted@example.com');
    $subject = $event->votingSubjects()->create([
        'slug' => 'test-voted-status',
        'title' => 'Audience choice',
        'status' => 'active',
    ]);
    $contestant = $subject->contestants()->create(['name' => 'Contestant One', 'display_order' => 1]);
    Registration::findOrFail($votedRegistrationId)->votingVotes()->create([
        'voting_subject_id' => $subject->id,
        'voting_contestant_id' => $contestant->id,
    ]);
    $nomination = $event->votingSubjects()->create([
        'slug' => 'test-nomination-activity',
        'title' => 'Audience nominations',
        'status' => 'closed',
        'mode' => 'nomination',
    ]);
    $nominee = $nomination->contestants()->create(['name' => 'Jamie Rivera', 'display_order' => 1]);
    Registration::findOrFail($votedRegistrationId)->votingVotes()->create([
        'voting_subject_id' => $nomination->id,
        'voting_contestant_id' => $nominee->id,
    ]);

    $response = $this->actingAs($user)->getJson('/api/events/'.$event->slug.'/registrations')->assertOk();
    $registrations = collect($response->json('data'))->keyBy('id');

    expect($registrations[$votedRegistrationId]['has_voted'])->toBeTrue()
        ->and($registrations[$votedRegistrationId]['voting_activity'])->toBe([
            ['subject_id' => $subject->id, 'nominee_name' => 'Contestant One'],
            ['subject_id' => $nomination->id, 'nominee_name' => 'Jamie Rivera'],
        ])
        ->and($registrations[$notVotedRegistrationId]['has_voted'])->toBeFalse()
        ->and($registrations[$notVotedRegistrationId]['voting_activity'])->toBe([])
        ->and($response->json('voting_subjects'))->toBe([
            ['id' => $subject->id, 'title' => 'Audience choice', 'mode' => 'manual', 'status' => 'active'],
            ['id' => $nomination->id, 'title' => 'Audience nominations', 'mode' => 'nomination', 'status' => 'closed'],
        ]);
});

test('an event owner can close and reopen registration and closed forms reject submissions', function () {
    $owner = User::factory()->create();
    $event = Event::findOrFail(
        $this->actingAs($owner)->postJson('/api/events', eventPayload())->json('data.id'),
    );
    $registrationUrl = '/api/registration/events/'.$event->slug;

    $this->patchJson('/api/events/'.$event->slug.'/registration', ['is_open' => false])
        ->assertOk()
        ->assertJsonPath('data.registration_is_open', false)
        ->assertJsonPath('message', 'Event registration is now closed.');

    $this->getJson($registrationUrl)
        ->assertOk()
        ->assertJsonPath('data.registration_is_open', false);

    $this->postJson($registrationUrl, ['answers' => []])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('event');

    $this->patchJson('/api/events/'.$event->slug.'/registration', ['is_open' => true])
        ->assertOk()
        ->assertJsonPath('data.registration_is_open', true)
        ->assertJsonPath('message', 'Event registration is now open.');
});

test('a registered attendee queues an email containing their qr pass', function () {
    Mail::fake();
    $user = User::factory()->create();
    $payload = eventPayload();
    $payload['qr_description'] = 'Bring this QR pass to the welcome desk.';
    $event = Event::findOrFail(
        $this->actingAs($user)->postJson('/api/events', $payload)->json('data.id'),
    );
    expect($event->qr_description)->toBe('Bring this QR pass to the welcome desk.');
    $mealValue = $event->activeRegistrationForm()->firstOrFail()
        ->fields()->where('key', 'meal')->firstOrFail()
        ->options()->firstOrFail()->value;

    $this->postJson('/api/registration/events/'.$event->slug, [
        'answers' => [
            'first-name' => 'Jamie',
            'last-name' => 'Rivera',
            'work-email' => 'jamie@example.com',
            'meal' => $mealValue,
        ],
    ])->assertOk()->assertExactJson(['email' => 'jamie@example.com']);

    $registrationCode = Registration::query()
        ->whereHas('attendee', fn ($query) => $query->where('email', 'jamie@example.com'))
        ->value('registration_code');

    Mail::assertQueued(RegistrationConfirmation::class, function (RegistrationConfirmation $mail) use ($event, $registrationCode): bool {
        $mail->assertHasTo('jamie@example.com');
        $mail->assertHasSubject('Your QR pass: '.$event->title);
        $mail->assertSeeInHtml('Jamie Rivera');
        $mail->assertSeeInHtml($registrationCode);
        $mail->assertSeeInHtml('Download QR pass (PDF)');
        $mail->assertSeeInHtml('Attendee registration QR code');
        $mail->assertSeeInHtml('Bring this QR pass to the welcome desk.');

        expect($mail->registration->registration_code)->toBe($registrationCode)
            ->and(substr($mail->qrPng(), 0, 8))->toBe("\x89PNG\r\n\x1a\n");

        return true;
    });

    $registration = Registration::query()
        ->whereHas('attendee', fn ($query) => $query->where('email', 'jamie@example.com'))
        ->firstOrFail();
    $downloadResponse = $this->get((new RegistrationConfirmation($registration))->qrDownloadUrl());
    $downloadResponse->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $downloadedPdf = $downloadResponse->getContent();
    expect(substr($downloadedPdf, 0, 8))->toBe('%PDF-1.4')
        ->and($downloadResponse->headers->get('Content-Disposition'))->toContain('.pdf')
        ->and($downloadedPdf)->toContain($event->title)
        ->toContain('Jamie Rivera')
        ->toContain($registrationCode)
        ->toContain('REGISTRATION CONFIRMED')
        ->toContain('Date:')
        ->toContain('Venue:')
        ->toContain('Bring this QR pass to the welcome desk.')
        ->toContain($event->venue ?: 'Online event');

    preg_match('/startxref\n(\d+)/', $downloadedPdf, $xrefMatch);
    expect(substr($downloadedPdf, (int) ($xrefMatch[1] ?? 0), 4))->toBe('xref');

    $event->update(['qr_description' => null]);
    (new RegistrationConfirmation($registration->fresh()))
        ->assertSeeInHtml('Please download the QR and note the registration code for event purposes');

    $expiredUrl = URL::temporarySignedRoute(
        'registrations.qr-download',
        now()->subMinute(),
        ['registration' => $registration->id],
    );
    $this->get($expiredUrl)->assertForbidden();
});

test('a mail queue failure does not discard the registration or qr code', function () {
    Mail::shouldReceive('to')->once()->with('jamie@example.com')->andThrow(new RuntimeException('Mail transport unavailable'));
    $user = User::factory()->create();
    $event = Event::findOrFail(
        $this->actingAs($user)->postJson('/api/events', eventPayload())->json('data.id'),
    );
    $mealValue = $event->activeRegistrationForm()->firstOrFail()
        ->fields()->where('key', 'meal')->firstOrFail()
        ->options()->firstOrFail()->value;

    $response = $this->postJson('/api/registration/events/'.$event->slug, [
        'answers' => [
            'first-name' => 'Jamie',
            'last-name' => 'Rivera',
            'work-email' => 'jamie@example.com',
            'meal' => $mealValue,
        ],
    ]);

    $response
        ->assertOk()
        ->assertExactJson(['email' => 'jamie@example.com']);

    $registration = Registration::query()
        ->whereHas('attendee', fn ($query) => $query->where('email', 'jamie@example.com'))
        ->firstOrFail();
    expect($registration->registration_code)->toStartWith('REG-');
    $this->assertDatabaseHas('registrations', [
        'id' => $registration->id,
        'status' => 'confirmed',
    ]);
});

test('an attendee cannot register for the same event twice', function () {
    $user = User::factory()->create();
    $event = Event::findOrFail(
        $this->actingAs($user)->postJson('/api/events', eventPayload())->json('data.id'),
    );
    $payload = ['answers' => ['first-name' => 'Jamie', 'last-name' => 'Rivera', 'work-email' => 'jamie@example.com', 'meal' => 'regular-1']];

    $this->postJson('/api/registration/events/'.$event->slug, $payload)->assertOk();
    $this->postJson('/api/registration/events/'.$event->slug, $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('email');
});

test('an event owner can check in a registered attendee', function () {
    $user = User::factory()->create();
    $event = Event::findOrFail(
        $this->actingAs($user)->postJson('/api/events', eventPayload())->json('data.id'),
    );
    $registration = $event->registrations()->create([
        'registration_form_id' => $event->activeRegistrationForm()->firstOrFail()->id,
        'attendee_id' => Attendee::create([
            'first_name' => 'Jamie',
            'last_name' => 'Rivera',
            'email' => 'jamie@example.com',
            'email_normalized' => 'jamie@example.com',
        ])->id,
        'registration_code' => 'REG-CHECKIN01',
        'status' => 'confirmed',
        'source' => 'public_form',
        'registered_at' => now(),
        'confirmed_at' => now(),
    ]);

    $this->actingAs($user)->postJson('/api/events/'.$event->slug.'/check-ins', [
        'registration_code' => $registration->registration_code,
        'gate' => 'Dashboard',
    ])->assertCreated()->assertJsonPath('data.result', 'accepted');

    $this->actingAs($user)->postJson('/api/events/'.$event->slug.'/check-ins', [
        'registration_code' => $registration->registration_code,
    ])->assertConflict()->assertJsonPath('data.result', 'duplicate');
});

test('a scanner can list published events from start-day midnight until their exact end time', function () {
    Carbon::setTestNow(Carbon::parse('2026-09-25T06:00:00Z'));

    try {
        $admin = User::factory()->create();
        $scanner = User::factory()->scanner()->create();

        $endedTodayPayload = eventPayload();
        $endedTodayPayload['title'] = 'Ended Earlier Today';
        $endedTodayPayload['starts_at'] = '2026-09-25T01:00:00+08:00';
        $endedTodayPayload['ends_at'] = '2026-09-25T02:00:00+08:00';
        $endedToday = Event::findOrFail(
            $this->actingAs($admin)->postJson('/api/events', $endedTodayPayload)->json('data.id'),
        );

        $laterTodayPayload = eventPayload();
        $laterTodayPayload['title'] = 'Starting Later Today';
        $laterTodayPayload['starts_at'] = '2026-09-25T23:00:00+08:00';
        $laterTodayPayload['ends_at'] = '2026-09-25T23:30:00+08:00';
        $laterToday = Event::findOrFail(
            $this->actingAs($admin)->postJson('/api/events', $laterTodayPayload)->json('data.id'),
        );

        $yesterdayPayload = eventPayload();
        $yesterdayPayload['title'] = 'Started Yesterday';
        $yesterdayPayload['starts_at'] = '2026-09-24T23:00:00+08:00';
        $yesterdayPayload['ends_at'] = '2026-09-25T23:00:00+08:00';
        $startedYesterday = Event::findOrFail(
            $this->actingAs($admin)->postJson('/api/events', $yesterdayPayload)->json('data.id'),
        );

        $tomorrowPayload = eventPayload();
        $tomorrowPayload['title'] = 'Starting Tomorrow';
        $tomorrowPayload['starts_at'] = '2026-09-26T01:00:00+08:00';
        $tomorrowPayload['ends_at'] = '2026-09-26T02:00:00+08:00';
        $this->actingAs($admin)->postJson('/api/events', $tomorrowPayload)->assertCreated();

        $draftPayload = $laterTodayPayload;
        $draftPayload['title'] = 'Today Private Draft';
        $draftPayload['status'] = 'draft';
        $this->actingAs($admin)->postJson('/api/events', $draftPayload)->assertCreated();

        $this->actingAs($scanner)
            ->getJson('/api/scanner/events')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['slug' => $laterToday->slug])
            ->assertJsonFragment(['slug' => $startedYesterday->slug])
            ->assertJsonMissing(['slug' => $endedToday->slug])
            ->assertJsonMissing(['title' => 'Starting Tomorrow'])
            ->assertJsonMissing(['title' => 'Today Private Draft']);
    } finally {
        Carbon::setTestNow();
    }
});

test('the scanner event query limits timezone candidates in the database', function () {
    $scanner = User::factory()->scanner()->create();
    DB::flushQueryLog();
    DB::enableQueryLog();

    try {
        $this->actingAs($scanner)->getJson('/api/scanner/events')->assertOk();

        $eventQuery = collect(DB::getQueryLog())->first(
            fn (array $query): bool => str_contains(strtolower($query['query']), 'from "events"'),
        );

        expect($eventQuery)->not->toBeNull()
            ->and(strtolower($eventQuery['query']))->toContain('"starts_at" <=')
            ->and(strtolower($eventQuery['query']))->toContain('"ends_at" >=');
    } finally {
        DB::disableQueryLog();
    }
});

test('event timestamps with offsets are normalized before ongoing filtering', function () {
    Carbon::setTestNow(Carbon::parse('2026-09-25T04:30:00Z'));

    try {
        $admin = User::factory()->create();
        $scanner = User::factory()->scanner()->create();
        $payload = eventPayload();
        $payload['starts_at'] = '2026-09-25T12:00:00+08:00';
        $payload['ends_at'] = '2026-09-25T13:00:00+08:00';

        $event = Event::findOrFail(
            $this->actingAs($admin)->postJson('/api/events', $payload)->json('data.id'),
        );

        expect($event->getRawOriginal('starts_at'))->toBe('2026-09-25 04:00:00')
            ->and($event->getRawOriginal('ends_at'))->toBe('2026-09-25 05:00:00');

        $this->actingAs($scanner)
            ->getJson('/api/scanner/events')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', $event->slug);
    } finally {
        Carbon::setTestNow();
    }
});

test('a scanner can check in a registered attendee by registration code', function () {
    $admin = User::factory()->create();
    $scanner = User::factory()->scanner()->create();
    $payload = eventPayload();
    $payload['starts_at'] = now('Asia/Manila')->startOfDay()->addHour()->toIso8601String();
    $payload['ends_at'] = now('Asia/Manila')->addHours(2)->toIso8601String();
    $event = Event::findOrFail(
        $this->actingAs($admin)->postJson('/api/events', $payload)->json('data.id'),
    );
    $registration = $event->registrations()->create([
        'registration_form_id' => $event->activeRegistrationForm()->firstOrFail()->id,
        'attendee_id' => Attendee::create([
            'first_name' => 'Alex',
            'last_name' => 'Santos',
            'email' => 'alex@example.com',
            'email_normalized' => 'alex@example.com',
        ])->id,
        'registration_code' => 'REG-SCANNER01',
        'status' => 'confirmed',
        'source' => 'public_form',
        'registered_at' => now(),
        'confirmed_at' => now(),
    ]);

    $this->actingAs($scanner)
        ->postJson('/api/events/'.$event->slug.'/check-ins', [
            'registration_code' => $registration->registration_code,
            'gate' => 'Scanner',
        ])
        ->assertCreated()
        ->assertJsonPath('data.result', 'accepted')
        ->assertJsonPath('data.registration.attendee.email', 'alex@example.com');
});

test('a scanner cannot check into a published event after its exact end time', function () {
    Carbon::setTestNow(Carbon::parse('2026-09-25T06:00:00Z'));

    try {
        $admin = User::factory()->create();
        $scanner = User::factory()->scanner()->create();
        $payload = eventPayload();
        $payload['starts_at'] = '2026-09-25T01:00:00+08:00';
        $payload['ends_at'] = '2026-09-25T02:00:00+08:00';
        $event = Event::findOrFail(
            $this->actingAs($admin)->postJson('/api/events', $payload)->json('data.id'),
        );
        $registration = $event->registrations()->create([
            'registration_form_id' => $event->activeRegistrationForm()->firstOrFail()->id,
            'attendee_id' => Attendee::create([
                'first_name' => 'Ended',
                'last_name' => 'Attendee',
                'email' => 'ended@example.com',
                'email_normalized' => 'ended@example.com',
            ])->id,
            'registration_code' => 'REG-ENDED001',
            'status' => 'confirmed',
            'source' => 'public_form',
            'registered_at' => now(),
            'confirmed_at' => now(),
        ]);

        $this->actingAs($scanner)
            ->postJson('/api/events/'.$event->slug.'/check-ins', [
                'registration_code' => $registration->registration_code,
            ])
            ->assertNotFound();

        $this->assertDatabaseCount('check_ins', 0);
    } finally {
        Carbon::setTestNow();
    }
});

test('a scanner cannot check into a published event that starts on another day', function () {
    $admin = User::factory()->create();
    $scanner = User::factory()->scanner()->create();
    $event = Event::findOrFail(
        $this->actingAs($admin)->postJson('/api/events', eventPayload())->json('data.id'),
    );
    $registration = $event->registrations()->create([
        'registration_form_id' => $event->activeRegistrationForm()->firstOrFail()->id,
        'attendee_id' => Attendee::create([
            'first_name' => 'Future',
            'last_name' => 'Attendee',
            'email' => 'future@example.com',
            'email_normalized' => 'future@example.com',
        ])->id,
        'registration_code' => 'REG-FUTURE01',
        'status' => 'confirmed',
        'source' => 'public_form',
        'registered_at' => now(),
        'confirmed_at' => now(),
    ]);

    $this->actingAs($scanner)
        ->postJson('/api/events/'.$event->slug.'/check-ins', [
            'registration_code' => $registration->registration_code,
        ])
        ->assertNotFound();

    $this->assertDatabaseCount('check_ins', 0);
});

test('a scanner cannot check attendees into a draft event', function () {
    $admin = User::factory()->create();
    $scanner = User::factory()->scanner()->create();
    $payload = eventPayload();
    $payload['status'] = 'draft';
    $event = Event::findOrFail(
        $this->actingAs($admin)->postJson('/api/events', $payload)->json('data.id'),
    );
    $registration = $event->registrations()->create([
        'registration_form_id' => $event->activeRegistrationForm()->firstOrFail()->id,
        'attendee_id' => Attendee::create([
            'first_name' => 'Draft',
            'last_name' => 'Attendee',
            'email' => 'draft@example.com',
            'email_normalized' => 'draft@example.com',
        ])->id,
        'registration_code' => 'REG-DRAFT001',
        'status' => 'confirmed',
        'source' => 'public_form',
        'registered_at' => now(),
        'confirmed_at' => now(),
    ]);

    $this->actingAs($scanner)
        ->postJson('/api/events/'.$event->slug.'/check-ins', [
            'registration_code' => $registration->registration_code,
        ])
        ->assertNotFound();

    $this->assertDatabaseCount('check_ins', 0);
});

test('cancelled and rejected registrations cannot be checked in', function () {
    $admin = User::factory()->create();
    $scanner = User::factory()->scanner()->create();
    $payload = eventPayload();
    $payload['starts_at'] = now('Asia/Manila')->startOfDay()->addHour()->toIso8601String();
    $payload['ends_at'] = now('Asia/Manila')->addHours(2)->toIso8601String();
    $event = Event::findOrFail(
        $this->actingAs($admin)->postJson('/api/events', $payload)->json('data.id'),
    );
    foreach (['cancelled', 'rejected'] as $status) {
        $email = $status.'@example.com';
        $attendee = Attendee::create([
            'first_name' => ucfirst($status),
            'last_name' => 'Attendee',
            'email' => $email,
            'email_normalized' => $email,
        ]);
        $registration = $event->registrations()->create([
            'registration_form_id' => $event->activeRegistrationForm()->firstOrFail()->id,
            'attendee_id' => $attendee->id,
            'registration_code' => 'REG-'.strtoupper($status),
            'status' => $status,
            'source' => 'public_form',
            'registered_at' => now(),
            'confirmed_at' => now(),
        ]);

        $this->actingAs($scanner)
            ->postJson('/api/events/'.$event->slug.'/check-ins', [
                'registration_code' => $registration->registration_code,
            ])
            ->assertNotFound();
    }

    $this->assertDatabaseCount('check_ins', 0);
});

test('an event owner can update an event and publish a new form version', function () {
    $user = User::factory()->create();
    $event = Event::findOrFail(
        $this->actingAs($user)->postJson('/api/events', eventPayload())->json('data.id'),
    );
    $updatedPayload = eventPayload();
    $updatedPayload['title'] = 'Updated Corporate Gala';
    $updatedPayload['registration_form'][] = [
        'key' => 'department',
        'system_key' => null,
        'type' => 'short',
        'label' => 'Department',
        'description' => null,
        'required' => false,
        'options' => [],
    ];

    $this->actingAs($user)
        ->putJson('/api/events/'.$event->slug, $updatedPayload)
        ->assertOk()
        ->assertJsonPath('data.title', 'Updated Corporate Gala')
        ->assertJsonPath('data.active_registration_form.version', 2)
        ->assertJsonCount(6, 'data.active_registration_form.fields');

    expect($event->registrationForms()->count())->toBe(2)
        ->and($event->registrationForms()->where('is_active', true)->count())->toBe(1);
});

test('an event owner can send invitations containing the registration link and qr code', function () {
    $user = User::factory()->create();
    $event = Event::findOrFail(
        $this->actingAs($user)->postJson('/api/events', eventPayload())->json('data.id'),
    );
    Mail::fake();

    $this->actingAs($user)
        ->postJson('/api/events/'.$event->slug.'/invitations', [
            'emails' => [' Guest@example.com ', 'guest@example.com', 'SECOND@example.com'],
        ])
        ->assertOk()
        ->assertJsonPath('sent_count', 2)
        ->assertJsonPath('failed_count', 0)
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('registration_url', 'http://localhost:3000/register/'.$event->slug);

    $this->assertDatabaseCount('email_invitations', 2);
    $this->assertDatabaseHas('email_invitations', [
        'event_id' => $event->id,
        'email' => 'guest@example.com',
        'status' => 'sent',
    ]);
    $this->assertDatabaseHas('email_invitations', [
        'event_id' => $event->id,
        'email' => 'second@example.com',
        'status' => 'sent',
    ]);

    $registrationUrl = 'http://localhost:3000/register/'.$event->slug;

    Mail::assertSent(EventInvitation::class, 2);
    Mail::assertSent(EventInvitation::class, function (EventInvitation $mail) use ($event, $registrationUrl): bool {
        $mail->assertHasSubject('Invitation: '.$event->title);
        $mail->assertSeeInHtml($registrationUrl);
        $mail->assertHasAttachedData($mail->qrPng, 'event-registration-qr.png', ['mime' => 'image/png']);

        expect($mail->registrationUrl)->toBe($registrationUrl)
            ->and(substr($mail->qrPng, 0, 8))->toBe("\x89PNG\r\n\x1a\n");

        return true;
    });
});

test('an event owner can retrieve only the twenty most recent invitation logs', function () {
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();
    $event = Event::findOrFail(
        $this->actingAs($owner)->postJson('/api/events', eventPayload())->json('data.id'),
    );

    foreach (range(1, 21) as $number) {
        EmailInvitation::create([
            'event_id' => $event->id,
            'email' => "guest{$number}@example.com",
            'status' => 'sent',
            'created_at' => now()->addSeconds($number),
            'updated_at' => now()->addSeconds($number),
        ]);
    }

    $this->actingAs($owner)
        ->getJson('/api/events/'.$event->slug.'/invitations')
        ->assertOk()
        ->assertJsonCount(20, 'data')
        ->assertJsonPath('data.0.email', 'guest21@example.com')
        ->assertJsonPath('data.0.event.title', $event->title)
        ->assertJsonMissingPath('data.0.failure_reason')
        ->assertJsonMissingPath('data.0.invited_by');

    $this->actingAs($otherUser)
        ->getJson('/api/events/'.$event->slug.'/invitations')
        ->assertNotFound();
});

test('an event owner can retrieve every registration for an excel export', function () {
    $owner = User::factory()->create();
    $event = Event::findOrFail(
        $this->actingAs($owner)->postJson('/api/events', eventPayload())->json('data.id'),
    );
    $form = $event->activeRegistrationForm()->firstOrFail();

    foreach (range(1, 51) as $number) {
        $attendee = Attendee::create([
            'first_name' => 'Guest',
            'last_name' => (string) $number,
            'email' => "guest{$number}@example.com",
            'email_normalized' => "guest{$number}@example.com",
        ]);

        $event->registrations()->create([
            'registration_form_id' => $form->id,
            'attendee_id' => $attendee->id,
            'registration_code' => 'REG-'.str_pad((string) $number, 8, '0', STR_PAD_LEFT),
            'status' => 'confirmed',
            'source' => 'public_form',
            'registered_at' => now()->addSeconds($number),
            'confirmed_at' => now()->addSeconds($number),
        ]);
    }

    $this->actingAs($owner)
        ->getJson('/api/events/'.$event->slug.'/registrations/export')
        ->assertOk()
        ->assertJsonCount(51, 'data')
        ->assertJsonPath('data.0.attendee.email', 'guest51@example.com');
});

test('an event owner can filter sort and paginate registrations', function () {
    $owner = User::factory()->create();
    $event = Event::findOrFail(
        $this->actingAs($owner)->postJson('/api/events', eventPayload())->json('data.id'),
    );
    $form = $event->activeRegistrationForm()->firstOrFail();
    $registrations = collect([
        ['first_name' => 'Noah', 'last_name' => 'Baker', 'status' => 'confirmed'],
        ['first_name' => 'Alice', 'last_name' => 'Jones', 'status' => 'confirmed'],
        ['first_name' => 'Mia', 'last_name' => 'Clark', 'status' => 'cancelled'],
    ])->map(function (array $details, int $index) use ($event, $form) {
        $email = strtolower($details['first_name']).'@example.com';
        $attendee = Attendee::create([
            'first_name' => $details['first_name'],
            'last_name' => $details['last_name'],
            'email' => $email,
            'email_normalized' => $email,
        ]);

        return $event->registrations()->create([
            'registration_form_id' => $form->id,
            'attendee_id' => $attendee->id,
            'registration_code' => 'REG-'.str_pad((string) ($index + 1), 8, '0', STR_PAD_LEFT),
            'status' => $details['status'],
            'source' => 'public_form',
            'registered_at' => now()->addSeconds($index),
            'confirmed_at' => $details['status'] === 'confirmed' ? now() : null,
        ]);
    });

    $registrations[1]->checkIns()->create([
        'checked_in_by' => $owner->id,
        'checked_in_at' => now(),
        'gate' => 'Main gate',
        'result' => 'accepted',
    ]);

    $url = '/api/events/'.$event->slug.'/registrations';

    $this->getJson($url.'?per_page=2&sort_by=name&sort_direction=asc')
        ->assertOk()
        ->assertJsonPath('total', 3)
        ->assertJsonPath('all_total', 3)
        ->assertJsonPath('current_page', 1)
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.attendee.first_name', 'Alice')
        ->assertJsonPath('data.1.attendee.first_name', 'Mia');

    $this->getJson($url.'?per_page=2&sort_by=name&sort_direction=asc&page=2')
        ->assertOk()
        ->assertJsonPath('current_page', 2)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.attendee.first_name', 'Noah');

    $this->getJson($url.'?search=Alice%20Jones&status=confirmed&check_in=checked_in')
        ->assertOk()
        ->assertJsonPath('total', 1)
        ->assertJsonPath('data.0.attendee.email', 'alice@example.com');

    $this->getJson($url.'?check_in=not_checked_in&status=cancelled')
        ->assertOk()
        ->assertJsonPath('total', 1)
        ->assertJsonPath('data.0.attendee.first_name', 'Mia');
});

test('a user cannot export another owners event registrations', function () {
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();
    $event = Event::findOrFail(
        $this->actingAs($owner)->postJson('/api/events', eventPayload())->json('data.id'),
    );

    $this->actingAs($otherUser)
        ->getJson('/api/events/'.$event->slug.'/registrations/export')
        ->assertNotFound();
});

test('an invitation batch rejects invalid addresses and more than one hundred recipients', function () {
    $user = User::factory()->create();
    $event = Event::findOrFail(
        $this->actingAs($user)->postJson('/api/events', eventPayload())->json('data.id'),
    );

    $this->actingAs($user)
        ->postJson('/api/events/'.$event->slug.'/invitations', [
            'emails' => ['not-an-email'],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('emails.0');

    $this->actingAs($user)
        ->postJson('/api/events/'.$event->slug.'/invitations', [
            'emails' => 'guest@example.com',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('emails');

    $this->actingAs($user)
        ->postJson('/api/events/'.$event->slug.'/invitations', [
            'emails' => collect(range(1, 101))->map(fn ($number) => "guest{$number}@example.com")->all(),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('emails');
});

test('a failed invitation delivery is recorded without its failure reason', function () {
    $user = User::factory()->create();
    $event = Event::findOrFail(
        $this->actingAs($user)->postJson('/api/events', eventPayload())->json('data.id'),
    );
    Mail::shouldReceive('to')
        ->once()
        ->with('guest@example.com')
        ->andThrow(new RuntimeException('SMTP credentials exposed here'));

    $response = $this->actingAs($user)
        ->postJson('/api/events/'.$event->slug.'/invitations', [
            'emails' => ['guest@example.com'],
        ])
        ->assertOk()
        ->assertJsonPath('sent_count', 0)
        ->assertJsonPath('failed_count', 1);

    expect($response->getContent())->not->toContain('SMTP credentials exposed here');

    $this->assertDatabaseHas('email_invitations', [
        'event_id' => $event->id,
        'email' => 'guest@example.com',
        'status' => 'failed',
    ]);
});

test('an event owner can update custom registration answers using saved field metadata', function () {
    $owner = User::factory()->create();
    $fixture = registrationAnswerEditingFixture($this, $owner);
    $fields = $fixture['fields'];
    $payload = [
        'answers' => [
            ['form_field_id' => $fields['meal']->id, 'answer' => 'vegetarian-2'],
            ['form_field_id' => $fields['attendee-story']->id, 'answer' => 'Updated answer'],
            ['form_field_id' => $fields['visit-date']->id, 'answer' => '2026-11-10'],
            ['form_field_id' => $fields['sessions']->id, 'answer' => ['evening-2']],
        ],
    ];

    $this->actingAs($owner)
        ->getJson('/api/events/'.$fixture['event']->slug.'/registrations')
        ->assertOk()
        ->assertJsonFragment(['label' => 'Regular', 'value' => 'regular-1']);

    $this->actingAs($owner)
        ->patchJson('/api/events/'.$fixture['event']->slug.'/registrations/'.$fixture['registration']->id.'/answers', $payload)
        ->assertOk()
        ->assertJsonPath('data.id', $fixture['registration']->id)
        ->assertJsonPath('data.attendee.first_name', 'Jeremiah')
        ->assertJsonPath('data.attendee.email', $fixture['attendee']->email)
        ->assertJsonFragment(['label' => 'Vegetarian', 'value' => 'vegetarian-2'])
        ->assertJsonPath('message', 'Registration answers updated successfully.');

    expect($fixture['registration']->answers()->where('form_field_id', $fields['meal']->id)->first()->answer)
        ->toBe('vegetarian-2');
    expect($fixture['registration']->answers()->where('form_field_id', $fields['attendee-story']->id)->first()->answer)
        ->toBe('Updated answer');
    expect($fixture['registration']->answers()->where('form_field_id', $fields['visit-date']->id)->first()->answer)
        ->toBe('2026-11-10');
    expect($fixture['registration']->answers()->where('form_field_id', $fields['sessions']->id)->first()->answer)
        ->toBe(['evening-2']);
    expect($fixture['attendee']->fresh()->only(['first_name', 'last_name', 'email']))
        ->toBe(['first_name' => 'Jeremiah', 'last_name' => 'Quintano', 'email' => $fixture['attendee']->email]);
});

test('an event owner can save an empty optional checkbox answer', function () {
    $owner = User::factory()->create();
    $fixture = registrationAnswerEditingFixture($this, $owner);
    $fields = $fixture['fields'];

    $this->actingAs($owner)
        ->patchJson('/api/events/'.$fixture['event']->slug.'/registrations/'.$fixture['registration']->id.'/answers', [
            'answers' => [
                ['form_field_id' => $fields['meal']->id, 'answer' => 'regular-1'],
                ['form_field_id' => $fields['attendee-story']->id, 'answer' => 'Original answer'],
                ['form_field_id' => $fields['visit-date']->id, 'answer' => '2026-11-09'],
                ['form_field_id' => $fields['sessions']->id, 'answer' => []],
            ],
        ])
        ->assertOk();

    expect($fixture['registration']->answers()->where('form_field_id', $fields['sessions']->id)->first()->answer)
        ->toBe([]);
});

test('registration answer updates reject invalid, missing, duplicate, and system fields without saving', function () {
    $owner = User::factory()->create();
    $fixture = registrationAnswerEditingFixture($this, $owner);
    $fields = $fixture['fields'];
    $validAnswers = [
        ['form_field_id' => $fields['meal']->id, 'answer' => 'vegetarian-2'],
        ['form_field_id' => $fields['attendee-story']->id, 'answer' => 'Updated answer'],
        ['form_field_id' => $fields['visit-date']->id, 'answer' => '2026-11-10'],
        ['form_field_id' => $fields['sessions']->id, 'answer' => ['evening-2']],
    ];
    $cases = [
        'required choice' => collect($validAnswers)->map(fn ($answer) => $answer['form_field_id'] === $fields['meal']->id
            ? [...$answer, 'answer' => '']
            : $answer)->all(),
        'invalid date' => collect($validAnswers)->map(fn ($answer) => $answer['form_field_id'] === $fields['visit-date']->id
            ? [...$answer, 'answer' => 'not-a-date']
            : $answer)->all(),
        'unsupported choice' => collect($validAnswers)->map(fn ($answer) => $answer['form_field_id'] === $fields['meal']->id
            ? [...$answer, 'answer' => 'unlisted-option']
            : $answer)->all(),
        'invalid checkbox option' => collect($validAnswers)->map(fn ($answer) => $answer['form_field_id'] === $fields['sessions']->id
            ? [...$answer, 'answer' => ['not-an-option']]
            : $answer)->all(),
        'omitted custom answer' => array_slice($validAnswers, 0, -1),
        'duplicate field id' => [...$validAnswers, $validAnswers[0]],
        'system field' => [...$validAnswers, [
            'form_field_id' => $fields['first-name']->id,
            'answer' => 'Changed name',
        ]],
    ];

    foreach ($cases as $case => $answers) {
        $this->actingAs($owner)
            ->patchJson('/api/events/'.$fixture['event']->slug.'/registrations/'.$fixture['registration']->id.'/answers', [
                'answers' => $answers,
            ])
            ->assertUnprocessable("Failed validation case: {$case}");
    }

    expect($fixture['registration']->answers()->where('form_field_id', $fields['meal']->id)->first()->answer)
        ->toBe('regular-1');
    expect($fixture['registration']->answers()->where('form_field_id', $fields['attendee-story']->id)->first()->answer)
        ->toBe('Original answer');
    expect($fixture['attendee']->fresh()->first_name)->toBe('Jeremiah');
});

test('registration answer updates are scoped to the event owner and event registration', function () {
    $owner = User::factory()->create();
    $otherOwner = User::factory()->create();
    $fixture = registrationAnswerEditingFixture($this, $owner);
    $otherFixture = registrationAnswerEditingFixture($this, $otherOwner);
    $payload = ['answers' => []];

    $this->actingAs($owner)
        ->patchJson('/api/events/'.$fixture['event']->slug.'/registrations/'.$otherFixture['registration']->id.'/answers', $payload)
        ->assertNotFound();

    $this->actingAs($otherOwner)
        ->patchJson('/api/events/'.$fixture['event']->slug.'/registrations/'.$fixture['registration']->id.'/answers', $payload)
        ->assertNotFound();
});
