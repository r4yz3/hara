<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Deliberately loose: stricter rules here would tell an attacker which
     * addresses and passwords are even possible.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:1024'],
        ];
    }

    public function email(): string
    {
        return strtolower(trim($this->string('email')->toString()));
    }
}
