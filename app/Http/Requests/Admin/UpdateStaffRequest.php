<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UpdateStaffRequest extends StoreStaffRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('user'));
    }

    public function rules(): array
    {
        $staff = $this->route('user');
        $isSelf = $this->user()->is($staff);

        return [
            ...parent::rules(),
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique(User::class)->ignore($staff)],
            // You cannot change your own role or deactivate yourself.
            'role' => $isSelf
                ? ['required', Rule::in([$staff->role->value])]
                : ['required', Rule::in($this->assignableRoleValues())],
            'is_active' => $isSelf ? ['boolean', 'accepted'] : ['boolean'],
            'password' => ['nullable', 'confirmed', Password::defaults()],
            'remove_pin' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            ...parent::messages(),
            'role.in' => $this->user()->is($this->route('user'))
                ? 'You cannot change your own role.'
                : 'You are not allowed to assign this role.',
            'is_active.accepted' => 'You cannot deactivate your own account.',
        ];
    }
}
