<?php

namespace StripeLri\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePackageRequest extends FormRequest
{
    public function authorize(): bool
    {
        $u = $this->user();

        return $u !== null && method_exists($u, 'isAdmin') && $u->isAdmin();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $creditRules = (bool) config('stripe-lri.credit_based')
            ? ['required', 'integer', 'min:0']
            : ['nullable', 'integer', 'min:0'];

        $siteLimitRules = (bool) config('stripe-lri.site_limit')
            ? ['required', 'integer', 'min:0']
            : ['nullable', 'integer', 'min:0'];

        return [
            'name' => ['required', 'string', 'max:255'],
            'package_type' => ['required', 'string', Rule::in(['stripe_plan', 'free', 'appsumo', 'custom'])],
            'credit_limit' => $creditRules,
            'site_limit' => $siteLimitRules,
            'status' => ['required', 'string', Rule::in(['active', 'inactive', 'draft'])],
            'is_popular' => ['nullable', 'boolean'],
            'is_featured' => ['nullable', 'boolean'],
            'description' => ['nullable', 'string'],
            'stripe_product_id' => ['nullable', 'string', 'max:255'],
            'payment_type' => ['nullable', 'string', 'max:64'],
            'user_scope' => ['nullable', 'string', 'max:64'],
            'items' => ['nullable', 'array'],
            'items.*.id' => ['nullable'],
            'items.*.name' => ['nullable', 'string', 'max:255'],
            'prices' => ['required', 'array', 'min:1'],
            'prices.*.plan_type' => ['required', 'string', Rule::in(['monthly', 'yearly', 'lifetime'])],
            'prices.*.amount' => ['required', 'numeric', 'min:0'],
            'prices.*.stripe_price_id' => ['nullable', 'string', 'max:255'],
            'prices.*.nickname' => ['nullable', 'string', 'max:255'],
            'prices.*.yearly_discount_percent' => ['nullable', 'integer', 'min:0', 'max:100'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (! $this->has('credit_limit') || $this->input('credit_limit') === null) {
            $this->merge(['credit_limit' => 0]);
        }
        if (! $this->has('site_limit') || $this->input('site_limit') === null) {
            $this->merge(['site_limit' => 0]);
        }
        $this->merge([
            'is_popular'  => (bool) $this->input('is_popular', false),
            'is_featured' => (bool) $this->input('is_featured', false),
        ]);
    }
}
