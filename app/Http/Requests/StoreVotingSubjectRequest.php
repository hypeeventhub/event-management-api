<?php

namespace App\Http\Requests;

use App\Models\Event;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator;

class StoreVotingSubjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        $event = $this->route('event');

        abort_unless($event instanceof Event && $event->created_by === $this->user()?->id, 404);

        return true;
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];

        if (is_string($this->input('title'))) {
            $normalized['title'] = trim($this->input('title'));
        }

        if (is_array($this->input('contestants'))) {
            $normalized['contestants'] = array_map(function ($contestant) {
                if (! is_array($contestant) || ! isset($contestant['name']) || ! is_string($contestant['name'])) {
                    return $contestant;
                }

                $contestant['name'] = trim($contestant['name']);

                return $contestant;
            }, $this->input('contestants'));
        }

        $this->merge($normalized);
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'contestants' => ['required', 'array', 'list', 'min:2', 'max:250'],
            'contestants.*' => ['required', 'array'],
            'contestants.*.name' => ['required', 'string', 'max:255'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $names = [];
            $contestants = $this->input('contestants');

            if (! is_array($contestants)) {
                return;
            }

            foreach ($contestants as $index => $contestant) {
                if (! is_array($contestant) || ! isset($contestant['name']) || ! is_string($contestant['name'])) {
                    continue;
                }

                $name = Str::lower(trim($contestant['name']));

                if ($name === '') {
                    continue;
                }

                if (isset($names[$name])) {
                    $validator->errors()->add("contestants.{$index}.name", 'Contestant names must be unique.');
                }

                $names[$name] = true;
            }
        }];
    }
}
