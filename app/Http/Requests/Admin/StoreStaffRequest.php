<?php

namespace App\Http\Requests\Admin;

use App\Enums\Role;
use App\Models\Location;
use App\Models\User;
use App\Rules\SriLankanPhone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreStaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', User::class);
    }

    public function rules(): array
    {
        $locations = self::assignableLocationIds($this->user());

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique(User::class)],
            'phone' => ['nullable', 'string', 'max:30', new SriLankanPhone],
            'role' => ['required', Rule::in($this->assignableRoleValues())],
            'is_active' => ['boolean'],
            'password' => ['required', 'confirmed', Password::defaults()],
            'pin' => ['nullable', 'digits_between:4,6'],
            // Admins work everywhere, so their home location is optional.
            'home_location_id' => [Rule::requiredIf($this->input('role') !== Role::Admin->value), 'nullable', 'integer', Rule::in($locations)],
            'location_ids' => ['nullable', 'array'],
            'location_ids.*' => ['integer', 'distinct', Rule::in($locations)],
        ];
    }

    public function messages(): array
    {
        return [
            'role.in' => 'You are not allowed to assign this role.',
            'pin.digits_between' => 'The PIN must be 4 to 6 digits.',
            'home_location_id.required' => 'Choose the location this person mainly works at.',
            'home_location_id.in' => 'You can only choose locations you manage.',
            'location_ids.*.in' => 'You can only choose locations you manage.',
        ];
    }

    public function attributes(): array
    {
        return ['home_location_id' => 'home location', 'location_ids' => 'other locations'];
    }

    /**
     * Locations the actor may put staff at: admins any active location, managers their own.
     *
     * @return array<int, int>
     */
    public static function assignableLocationIds(User $actor): array
    {
        return Location::query()->accessibleTo($actor)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * @return array<int, string>
     */
    protected function assignableRoleValues(): array
    {
        return array_map(fn ($role) => $role->value, $this->user()->role->assignableRoles());
    }
}
