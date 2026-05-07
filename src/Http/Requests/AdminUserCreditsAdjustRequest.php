<?php

namespace StripeLri\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminUserCreditsAdjustRequest extends FormRequest
{
    public function authorize(): bool
    {
        $u = $this->user();

        return $u !== null && method_exists($u, 'isAdmin') && $u->isAdmin();
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'action' => ['required', Rule::in(['add', 'remove'])],
            'amount' => ['required', 'integer', 'min:1', 'max:100000000'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
