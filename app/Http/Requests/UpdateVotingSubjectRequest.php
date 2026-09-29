<?php

namespace App\Http\Requests;

use App\Models\Event;
use App\Models\VotingSubject;

class UpdateVotingSubjectRequest extends StoreVotingSubjectRequest
{
    public function authorize(): bool
    {
        parent::authorize();

        /** @var Event $event */
        $event = $this->route('event');
        $subject = $event->votingSubjects()->where('slug', $this->route('subject'))->firstOrFail();
        abort_unless($subject->status === VotingSubject::STATUS_DRAFT, 409, 'Only draft voting subjects can be edited.');

        return true;
    }

    public function rules(): array
    {
        return parent::rules();
    }
}
