<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The only editable profile field is the display name. Every other key in the
 * body is ignored: validated() returns the rules' keys and nothing else.
 */
class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            // No control characters (\p{Cc}: newlines, tabs, NUL and the rest) and
            // no invisible format characters (\p{Cf}: bidirectional overrides,
            // zero-width spaces, BOM), which let a name display as something it
            // is not. The zero-width joiner and non-joiner stay allowed: some
            // scripts and emoji need them.
            'name' => ['required', 'string', 'min:1', 'max:160', 'regex:/\A(?:[^\p{Cc}\p{Cf}]|[\x{200C}\x{200D}])*\z/u'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['name.regex' => 'The name must not contain control or invisible formatting characters.'];
    }
}
