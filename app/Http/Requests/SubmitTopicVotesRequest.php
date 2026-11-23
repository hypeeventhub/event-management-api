<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SubmitTopicVotesRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'registration_code' => ['required', 'string', 'max:255'],
            'female_contestant_id' => ['required', 'integer', 'min:1'],
            'male_contestant_id' => ['required', 'integer', 'min:1'],
        ];
    }
}
