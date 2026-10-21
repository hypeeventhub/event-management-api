<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'qr_description' => ['nullable', 'string', 'max:10000'],
            'category' => ['nullable', 'string', 'max:100'],
            'capacity' => ['nullable', 'integer', 'min:1'],
            'venue' => ['nullable', 'string', 'max:500'],
            'meeting_url' => ['nullable', 'url', 'max:2048'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'timezone' => ['required', 'timezone:all'],
            'registration_opens_at' => ['nullable', 'date'],
            'registration_closes_at' => ['nullable', 'date', 'after:registration_opens_at'],
            'status' => ['required', Rule::in(['draft', 'published', 'live'])],
            'registration_form' => ['required', 'array', 'min:2'],
            'registration_form.*.key' => ['required', 'string', 'max:100', 'distinct'],
            'registration_form.*.system_key' => ['nullable', Rule::in(['first_name', 'last_name', 'email', 'phone', 'company'])],
            'registration_form.*.type' => ['required', Rule::in(['short', 'paragraph', 'multiple', 'checkboxes', 'dropdown', 'date'])],
            'registration_form.*.label' => ['required', 'string', 'max:255'],
            'registration_form.*.description' => ['nullable', 'string', 'max:2000'],
            'registration_form.*.required' => ['required', 'boolean'],
            'registration_form.*.options' => ['present', 'array'],
            'registration_form.*.options.*' => ['required', 'string', 'max:255', 'distinct'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $fields = collect($this->input('registration_form', []));
            $systemKeys = $fields->pluck('system_key');

            if (! $systemKeys->contains('first_name')) {
                $validator->errors()->add('registration_form', 'The form must contain a First name field.');
            }

            if (! $systemKeys->contains('last_name')) {
                $validator->errors()->add('registration_form', 'The form must contain a Last name field.');
            }

            if (! $systemKeys->contains('email')) {
                $validator->errors()->add('registration_form', 'The form must contain an Email field.');
            }

            foreach (['first_name' => 'First name', 'last_name' => 'Last name', 'email' => 'Email'] as $key => $label) {
                $field = $fields->firstWhere('system_key', $key);

                if ($field && ! ($field['required'] ?? false)) {
                    $validator->errors()->add('registration_form', "$label must be required.");
                }
            }
        }];
    }
}
