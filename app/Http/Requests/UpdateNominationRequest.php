<?php

namespace App\Http\Requests;

use App\Models\VotingSubject;

class UpdateNominationRequest extends StoreNominationRequest
{
    public function authorize(): bool
    {
        parent::authorize();

        $subject = $this->route('event')->nominationSubjects()
            ->whereKey($this->route('subject')->id)->firstOrFail();
        abort_unless($subject->status === VotingSubject::STATUS_DRAFT, 409, 'Only draft nominations can be edited.');

        return true;
    }
}
