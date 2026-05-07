<?php

namespace StripeLri\Http\Requests;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class AdminUserUpdateRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->has('handle') && $this->input('handle') === '') {
            $this->merge(['handle' => null]);
        }
    }

    public function authorize(): bool
    {
        $u = $this->user();

        return $u !== null && method_exists($u, 'isAdmin') && $u->isAdmin();
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $table = (string) config('stripe-lri.tables.users', 'users');

        $subject = $this->subject();

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', 'max:255', Rule::unique($table, 'email')->ignore((int) $subject->getKey())],
            'handle' => ['sometimes', 'nullable', 'string', 'max:255', Rule::unique($table, 'username')->ignore((int) $subject->getKey())],
            'role' => ['sometimes', Rule::in(['admin', 'user'])],
            'is_active' => ['sometimes', 'boolean'],
            'plan_credits' => ['sometimes', 'integer', 'min:0', 'max:2147483647'],
            'credits' => ['sometimes', 'integer', 'min:0', 'max:2147483647'],
            'credits_used' => ['sometimes', 'integer', 'min:0', 'max:2147483647'],
            'password' => ['sometimes', 'nullable', 'string', 'min:8', 'confirmed'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $subject = $this->subject();
            $actor = $this->user();

            if ($subject === null || $actor === null) {
                return;
            }

            if ($actor->getKey() === $subject->getKey()) {
                if ($this->has('role') && $this->input('role') === 'user') {
                    $validator->errors()->add('role', 'You cannot remove your own admin access from this screen.');
                }
                if ($this->has('is_active') && ! $this->boolean('is_active')) {
                    $validator->errors()->add('is_active', 'You cannot deactivate your own account.');
                }
            }
        });
    }

    private function subject(): Model
    {
        /** @var class-string<Model> $class */
        $class = config('stripe-lri.models.user');
        $id = (int) $this->route('user');

        return $class::query()->whereKey($id)->firstOrFail();
    }
}
