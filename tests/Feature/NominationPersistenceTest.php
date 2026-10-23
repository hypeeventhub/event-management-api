<?php

use App\Models\Attendee;
use App\Models\Event;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;

test('voting subjects default to manual and persist nomination metadata', function () {
    $event = nominationPersistenceEvent('subject-default');

    $manual = $event->votingSubjects()->create([
        'slug' => hash('sha256', 'manual-subject'),
        'title' => 'Manual Subject',
    ]);
    $nomination = $event->votingSubjects()->create([
        'slug' => hash('sha256', 'nomination-subject'),
        'title' => 'Nomination Subject',
        'mode' => 'nomination',
        'top_n' => 3,
    ]);

    expect($manual->fresh()->mode)->toBe('manual')
        ->and($manual->fresh()->top_n)->toBeNull()
        ->and($nomination->fresh()->mode)->toBe('nomination')
        ->and($nomination->fresh()->top_n)->toBe(3)
        ->and($event->nominationSubjects()->pluck('id')->all())->toBe([$nomination->id]);
});

test('nomination contestants link to attendees while retaining their nominee relation', function () {
    $event = nominationPersistenceEvent('attendee-link');
    $attendee = Attendee::create([
        'first_name' => 'Morgan',
        'last_name' => 'Lee',
        'email' => 'morgan@example.com',
        'email_normalized' => 'morgan@example.com',
    ]);
    $subject = $event->votingSubjects()->create([
        'slug' => hash('sha256', 'attendee-nomination'),
        'title' => 'Nominees',
        'mode' => 'nomination',
        'top_n' => 3,
    ]);
    $contestant = $subject->contestants()->create([
        'name' => 'Morgan Lee',
        'attendee_id' => $attendee->id,
    ]);

    expect($contestant->fresh()->attendee->is($attendee))->toBeTrue()
        ->and($contestant->fresh()->subject->is($subject))->toBeTrue();
});

test('generated draft subjects point uniquely back to their nomination subject', function () {
    $event = nominationPersistenceEvent('generated-link');
    $nomination = $event->votingSubjects()->create([
        'slug' => hash('sha256', 'source-nomination'),
        'title' => 'Source Nomination',
        'mode' => 'nomination',
        'top_n' => 3,
    ]);
    $draft = $event->votingSubjects()->create([
        'slug' => hash('sha256', 'generated-draft'),
        'title' => 'Generated Draft',
        'generated_from_subject_id' => $nomination->id,
    ]);

    expect($draft->fresh()->generatedFromSubject->is($nomination))->toBeTrue()
        ->and($nomination->fresh()->generatedSubject->is($draft))->toBeTrue();

    expect(fn () => $event->votingSubjects()->create([
        'slug' => hash('sha256', 'second-generated-draft'),
        'title' => 'Second Generated Draft',
        'generated_from_subject_id' => $nomination->id,
    ]))->toThrow(UniqueConstraintViolationException::class);
});

function nominationPersistenceEvent(string $key): Event
{
    $owner = User::factory()->create();

    return Event::create([
        'created_by' => $owner->id,
        'title' => 'Nomination Persistence Event',
        'slug' => $key,
        'starts_at' => now()->addDay(),
        'ends_at' => now()->addDays(2),
    ]);
}
