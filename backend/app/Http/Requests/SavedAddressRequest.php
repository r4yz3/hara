<?php

namespace App\Http\Requests;

use App\Support\Http\PositionInput;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Body of POST (all required fields) and PATCH (any subset) on
 * /me/addresses. A saved address is a person's exact home position, so:
 * the position is refused in the URL (ADR-013), and `city_id`, `user_id` and
 * `id` are never read: validated() holds the rules' keys and nothing else.
 */
class SavedAddressRequest extends FormRequest
{
    /** Keys a PATCH may change; a body with none of them is refused. */
    public const EDITABLE = ['address_line', 'landmark', 'tag', 'latitude', 'longitude', 'is_default'];

    /** Same rule as the profile name: no control (\p{Cc}) or invisible format (\p{Cf}) characters, joiners allowed. */
    private const TEXT = '/\A(?:[^\p{Cc}\p{Cf}]|[\x{200C}\x{200D}])*\z/u';

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        PositionInput::refuseInUrl($this);

        $trimmed = [];
        foreach (['address_line', 'landmark', 'tag'] as $key) {
            if (is_string($this->input($key))) {
                $trimmed[$key] = trim($this->input($key));
            }
        }
        // A blank tag means no tag.
        if (($trimmed['tag'] ?? null) === '') {
            $trimmed['tag'] = null;
        }
        $this->merge($trimmed);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $text = ['string', 'min:1', 'regex:'.self::TEXT];

        if ($this->isMethod('POST')) {
            return [
                'address_line' => ['required', ...$text, 'max:500'],
                'landmark' => ['required', ...$text, 'max:255'],
                'tag' => ['nullable', ...$text, 'max:40'],
                'latitude' => ['required', PositionInput::decimal()],
                'longitude' => ['required', PositionInput::decimal()],
                'is_default' => ['sometimes', 'boolean'],
            ];
        }

        // PATCH: a field that is present must be valid (`filled` refuses null);
        // the two halves of a position travel together.
        return [
            'address_line' => ['filled', ...$text, 'max:500'],
            'landmark' => ['filled', ...$text, 'max:255'],
            'tag' => ['nullable', ...$text, 'max:40'],
            'latitude' => ['required_with:longitude', 'filled', PositionInput::decimal()],
            'longitude' => ['required_with:latitude', 'filled', PositionInput::decimal()],
            'is_default' => ['filled', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->isMethod('PATCH') && array_intersect(self::EDITABLE, array_keys($this->all())) === []) {
                $validator->errors()->add('address_line', 'Send at least one field to change.');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'address_line.regex' => 'The address must not contain control or invisible formatting characters.',
            'landmark.regex' => 'The landmark must not contain control or invisible formatting characters.',
            'tag.regex' => 'The tag must not contain control or invisible formatting characters.',
        ];
    }
}
