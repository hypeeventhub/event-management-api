<?php

namespace App\Http\Requests;

use App\Models\Event;
use Illuminate\Foundation\Http\FormRequest;

class StoreNominationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $event = $this->route('event');
        abort_unless($event instanceof Event && $event->created_by === $this->user()?->id, 404);

        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('title'))) {
            $this->merge(['title' => trim($this->input('title'))]);
        }
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'top_n' => ['required', 'integer', 'min:2', 'max:4294967295'],
            // Nominees are collected from attendee QR submissions after this
            // subject is created and opened, never preselected by an admin.
            'attendee_ids' => ['prohibited'],
        ];
    }
}
