<?php

declare(strict_types=1);

namespace StripeLri\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCouponRequest extends FormRequest
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
            'name'            => ['required', 'string', 'max:255'],
            'code'            => ['required', 'string', 'max:50', 'regex:/^[A-Z0-9_\-]+$/', Rule::unique('coupons', 'code')],
            'description'     => ['nullable', 'string'],
            'coupon_type'     => ['required', Rule::in(['percent', 'fixed_amount'])],
            'value'           => ['required', 'numeric', 'min:0.01'],
            'currency'        => ['nullable', 'string', 'size:3'],
            'max_redemptions' => ['nullable', 'integer', 'min:1'],
            'is_active'       => ['boolean'],
            'valid_from'      => ['nullable', 'date'],
            'valid_until'     => ['nullable', 'date', 'after_or_equal:valid_from'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('code')) {
            $this->merge(['code' => strtoupper((string) $this->input('code'))]);
        }
        if (! $this->has('is_active')) {
            $this->merge(['is_active' => true]);
        }
    }
}
