<?php

namespace App\Http\Requests\Admin;

use App\Enums\LocationType;
use App\Models\Location;
use App\Rules\SriLankanPhone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A location's details plus its settings (receipt texts, business-day cutoff, terminal auto-lock).
 */
class LocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $location = $this->route('location');

        return $location ? $this->user()->can('update', $location) : $this->user()->can('create', Location::class);
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['code' => strtoupper(trim((string) $this->input('code')))]);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'code' => ['required', 'string', 'max:10', 'alpha_num:ascii', Rule::unique(Location::class)->ignore($this->route('location'))],
            'type' => ['required', Rule::enum(LocationType::class)],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30', new SriLankanPhone],
            'receipt_header' => ['nullable', 'string', 'max:1000'],
            'receipt_footer' => ['nullable', 'string', 'max:1000'],
            'business_day_starts_at' => ['required', 'date_format:H:i', 'before_or_equal:06:00'],
            'auto_lock_minutes' => ['required', 'integer', 'between:0,120'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.alpha_num' => 'Use letters and numbers only, e.g. HO or MS1.',
            'business_day_starts_at.before_or_equal' => 'The business day must start between 00:00 and 06:00.',
        ];
    }

    public function attributes(): array
    {
        return ['business_day_starts_at' => 'business day start', 'auto_lock_minutes' => 'auto-lock'];
    }

    /**
     * @return array<string, mixed>
     */
    public function locationData(): array
    {
        $data = $this->safe()->only(['name', 'code', 'type', 'address', 'phone']);
        $data['phone'] = blank($data['phone'] ?? null) ? null : SriLankanPhone::normalize($data['phone']);

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    public function settingsData(): array
    {
        return $this->safe()->only(['receipt_header', 'receipt_footer', 'business_day_starts_at', 'auto_lock_minutes']);
    }
}
