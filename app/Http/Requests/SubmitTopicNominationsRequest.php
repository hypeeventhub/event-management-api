<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SubmitTopicNominationsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'registration_code' => ['required', 'string', 'max:255'],
            'female_attendee_id' => ['required', 'integer', 'min:1', 'different:male_attendee_id'],
            'male_attendee_id' => ['required', 'integer', 'min:1', 'different:female_attendee_id'],
        ];
    }

    public function messages(): array
    {
        return [
            'female_attendee_id.different' => 'Choose different attendees for the Female and Male nominations.',
            'male_attendee_id.different' => 'Choose different attendees for the Female and Male nominations.',
        ];
    }
}
