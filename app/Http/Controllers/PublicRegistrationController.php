<?php

namespace App\Http\Controllers;

use App\Mail\RegistrationConfirmation;
use App\Models\Attendee;
use App\Models\Event;
use App\Models\Registration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PublicRegistrationController extends Controller
{
    public function show(Event $event): JsonResponse
    {
        abort_unless(in_array($event->status, ['published', 'live'], true), 404);

        return response()->json([
            'data' => $event->load(['activeRegistrationForm.fields.options']),
        ]);
    }

    public function store(Request $request, Event $event): JsonResponse
    {
        abort_unless(in_array($event->status, ['published', 'live'], true), 404);

        if (! $event->registration_is_open) {
            throw ValidationException::withMessages(['event' => 'Registration for this event is closed.']);
        }

        $form = $event->activeRegistrationForm()->with('fields.options')->firstOrFail();
        $rules = ['answers' => ['required', 'array']];

        foreach ($form->fields as $field) {
            $key = 'answers.'.$field->key;
            $fieldRules = $field->is_required ? ['required'] : ['nullable'];

            if (in_array($field->type, ['short', 'paragraph'], true)) {
                $fieldRules[] = 'string';
                $fieldRules[] = $field->type === 'short' ? 'max:1000' : 'max:5000';
            } elseif ($field->type === 'date') {
                $fieldRules[] = 'date_format:Y-m-d';
            } elseif (in_array($field->type, ['multiple', 'dropdown'], true)) {
                $fieldRules[] = Rule::in($field->options->pluck('value')->all());
            } elseif ($field->type === 'checkboxes') {
                $fieldRules[] = 'array';
                $rules[$key.'.*'] = [Rule::in($field->options->pluck('value')->all())];
            }

            $rules[$key] = $fieldRules;
        }

        $answers = Validator::make($request->all(), $rules)->validate()['answers'];
        $firstNameField = $form->fields->firstWhere('system_key', 'first_name');
        $lastNameField = $form->fields->firstWhere('system_key', 'last_name');
        $emailField = $form->fields->firstWhere('system_key', 'email');
        $email = Str::lower(trim((string) ($answers[$emailField->key] ?? '')));
        $firstName = trim((string) ($answers[$firstNameField->key] ?? ''));
        $lastName = trim((string) ($answers[$lastNameField->key] ?? ''));

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages([$emailField->key => 'A valid email address is required.']);
        }

        $registration = DB::transaction(function () use ($event, $form, $answers, $email, $firstName, $lastName): Registration {
            $lockedEvent = Event::query()->lockForUpdate()->findOrFail($event->id);

            if (! $lockedEvent->registration_is_open) {
                throw ValidationException::withMessages(['event' => 'Registration for this event is closed.']);
            }

            $registrationCount = $lockedEvent->registrations()
                ->whereNotIn('status', ['cancelled', 'rejected'])
                ->count();

            if ($lockedEvent->capacity && $registrationCount >= $lockedEvent->capacity) {
                throw ValidationException::withMessages(['event' => 'This event has reached its capacity.']);
            }

            $attendee = Attendee::updateOrCreate(
                ['email_normalized' => $email],
                ['email' => $email, 'first_name' => $firstName, 'last_name' => $lastName],
            );

            if ($lockedEvent->registrations()->where('attendee_id', $attendee->id)->exists()) {
                throw ValidationException::withMessages(['email' => 'This email is already registered for the event.']);
            }

            $registration = $lockedEvent->registrations()->create([
                'registration_form_id' => $form->id,
                'attendee_id' => $attendee->id,
                'registration_code' => $this->uniqueRegistrationCode(),
                'status' => 'confirmed',
                'source' => 'public_form',
                'qr_token_hash' => hash('sha256', Str::random(64)),
                'registered_at' => now(),
                'confirmed_at' => now(),
            ]);

            foreach ($form->fields as $field) {
                $registration->answers()->create([
                    'form_field_id' => $field->id,
                    'answer' => $answers[$field->key] ?? null,
                ]);
            }

            return $registration;
        });

        $registration->load(['attendee', 'event', 'answers.field']);
        $emailSendingDisabledForStaging = app()->environment('staging');
        $emailQueued = false;

        if (! $emailSendingDisabledForStaging) {
            try {
                Mail::to($registration->attendee->email)
                    ->queue(new RegistrationConfirmation($registration));
                $emailQueued = true;
            } catch (\Throwable $exception) {
                Log::warning('Registration QR email could not be queued.', [
                    'registration_id' => $registration->id,
                    'exception' => $exception::class,
                ]);
            }
        }

        return response()->json([
            'data' => $registration,
            'email_queued' => $emailQueued,
            'message' => $emailSendingDisabledForStaging
                ? 'Registration completed. Email delivery is disabled in staging; download and save your QR pass.'
                : ($emailQueued
                    ? 'Registration completed successfully. Your QR pass will be emailed shortly.'
                    : 'Registration completed, but the QR email could not be queued. Download your QR pass now.'),
        ], 201);
    }

    private function uniqueRegistrationCode(): string
    {
        do {
            $code = 'REG-'.Str::upper(Str::random(10));
        } while (Registration::where('registration_code', $code)->exists());

        return $code;
    }
}
