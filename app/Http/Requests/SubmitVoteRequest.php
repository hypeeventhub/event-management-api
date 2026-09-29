<?php

namespace App\Http\Requests;

use App\Models\VotingSubject;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class SubmitVoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        $subject = $this->route('subject');

        if (! $subject instanceof VotingSubject || $subject->status !== VotingSubject::STATUS_ACTIVE) {
            throw new HttpResponseException(response()->json(['message' => 'Not Found'], 404));
        }

        return true;
    }

    public function rules(): array
    {
        return [
            'registration_code' => ['required', 'string', 'max:255'],
            'contestant_id' => ['required', 'integer'],
        ];
    }
}
