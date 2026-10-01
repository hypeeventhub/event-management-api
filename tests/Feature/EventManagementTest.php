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
        ->assertCreated()
        ->assertJsonPath('data.attendee.email', 'jamie@example.com');

    expect(Attendee::count())->toBe(1)
        ->and(Registration::count())->toBe(1);
    $this->assertDatabaseCount('registration_answers', 4);

    $this->actingAs($user)
        ->getJson('/api/events/'.$event->slug.'/registrations')
        ->assertOk()
        ->assertJsonPath('data.0.attendee.first_name', 'Jamie')
        ->assertJsonPath('data.0.attendee.last_name', 'Rivera');
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
    ])->assertCreated()->assertJsonPath('email_queued', true);

    $registrationCode = $response->json('data.registration_code');

    Mail::assertQueued(RegistrationConfirmation::class, function (RegistrationConfirmation $mail) use ($event, $registrationCode): bool {
        $mail->assertHasTo('jamie@example.com');
        $mail->assertHasSubject('Your QR pass: '.$event->title);
        $mail->assertSeeInHtml('Jamie Rivera');
        $mail->assertSeeInHtml($registrationCode);
        $mail->assertHasAttachedData($mail->qrPng(), 'attendee-qr-pass.png', ['mime' => 'image/png']);

        expect($mail->registration->registration_code)->toBe($registrationCode)
            ->and(substr($mail->qrPng(), 0, 8))->toBe("\x89PNG\r\n\x1a\n");

        return true;
    });
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
        ->assertCreated()
        ->assertJsonPath('email_queued', false)
        ->assertJsonPath('message', 'Registration completed, but the QR email could not be queued. Download your QR pass now.');

    expect($response->json('data.registration_code'))->toStartWith('REG-');
    $this->assertDatabaseHas('registrations', [
        'registration_code' => $response->json('data.registration_code'),
        'status' => 'confirmed',
    ]);
});

test('an attendee cannot register for the same event twice', function () {
    $user = User::factory()->create();
    $event = Event::findOrFail(
        $this->actingAs($user)->postJson('/api/events', eventPayload())->json('data.id'),
    );
    $payload = ['answers' => ['first-name' => 'Jamie', 'last-name' => 'Rivera', 'work-email' => 'jamie@example.com', 'meal' => 'regular-1']];

    $this->postJson('/api/registration/events/'.$event->slug, $payload)->assertCreated();
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
        ->assertJsonCount(5, 'data.active_registration_form.fields');

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
