<?php

namespace App\Http\Requests;

use App\Models\Event;
use Illuminate\Foundation\Http\FormRequest;

class StoreNominationTopicsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $event = $this->route('event');
        abort_unless($event instanceof Event && $event->created_by === $this->user()?->id, 404);

        return true;
    }

    protected function prepareForValidation(): void
    {
        $topics = $this->input('topics');
        if (! is_array($topics)) {
            return;
        }

        foreach ($topics as $index => $topic) {
            if (is_array($topic)) {
                // The case-insensitive distinct rule examines sibling titles as strings.
                $topics[$index]['title'] = is_string($topic['title'] ?? null) ? trim($topic['title']) : '';
            }
        }

        $this->merge(['topics' => $topics]);
    }

    public function rules(): array
    {
        $finalistCount = ['required', 'integer', 'min:2', 'max:2147483647'];

        return [
            'topics' => ['required', 'array', 'list', 'min:1', 'max:30'],
            'topics.*' => ['required', 'array:title,female_top_n,male_top_n'],
            'topics.*.title' => ['bail', 'required', 'string', 'max:255', 'distinct:ignore_case'],
            'topics.*.female_top_n' => $finalistCount,
            'topics.*.male_top_n' => $finalistCount,
        ];
    }
}
