<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Re-authentication for closing the account. Shape only: the password itself
 * is checked by the service, behind a per-account limit.
 */
class DeleteAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return ['password' => ['required', 'string', 'max:1024']];
    }
}
