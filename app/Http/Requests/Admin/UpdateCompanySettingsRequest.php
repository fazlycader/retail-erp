<?php

namespace App\Http\Requests\Admin;

use App\Enums\DocumentType;
use App\Rules\SriLankanPhone;
use App\Support\BasisPoints;
use App\Support\ImageUploader;
use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCompanySettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage-settings');
    }

    protected function prepareForValidation(): void
    {
        $prefixes = (array) $this->input('document_prefixes', []);

        $this->merge([
            'vat_number' => trim((string) $this->input('vat_number')) ?: null,
            'document_prefixes' => array_map(fn ($prefix) => strtoupper(trim((string) $prefix)), $prefixes),
        ]);

        if ($this->has('report_emails')) {
            $this->merge(['report_emails_list' => array_values(array_filter(array_map('trim', preg_split('/[,;\s]+/', (string) $this->input('report_emails')) ?: [])))]);
        }
    }

    public function rules(): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'logo' => ImageUploader::rules(),
            'remove_logo' => ['boolean'],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30', new SriLankanPhone],
            'email' => ['nullable', 'email', 'max:255'],
            'vat_registered' => ['boolean'],
            'vat_number' => ['nullable', Rule::requiredIf($this->boolean('vat_registered')), 'string', 'max:30', 'regex:/^[0-9A-Za-z\-\s]+$/'],
            'vat_rate' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:100'],
            'prices_include_vat' => ['boolean'],
            'allow_negative_stock' => ['boolean'],
            'managers_edit_catalog' => ['boolean'],
            'adjustment_approval_value' => ['nullable', 'numeric', 'decimal:0,2', 'min:0'],
            'return_days' => ['sometimes', 'integer', 'min:0', 'max:365'],
            'credit_note_expiry_days' => ['sometimes', 'integer', 'min:0', 'max:3650'],
            'managers_see_profit' => ['sometimes', 'boolean'],
            'report_emails' => ['sometimes', 'nullable', 'string', 'max:500'],
            'report_emails_list' => ['array', 'max:10'],
            'report_emails_list.*' => ['email:rfc'],
            'daily_email_time' => ['sometimes', 'date_format:H:i'],
            'document_prefixes' => ['required', 'array'],
        ];

        foreach (DocumentType::cases() as $type) {
            $rules["document_prefixes.{$type->value}"] = ['required', 'string', 'max:6', 'alpha_num:ascii'];
        }

        return $rules;
    }

    public function after(): array
    {
        return [function ($validator) {
            $prefixes = array_filter((array) $this->input('document_prefixes'));
            $duplicates = array_diff_assoc($prefixes, array_unique($prefixes));

            foreach (array_keys($duplicates) as $key) {
                $validator->errors()->add("document_prefixes.{$key}", 'Each document needs its own prefix.');
            }
        }];
    }

    public function messages(): array
    {
        return [
            ...ImageUploader::messages('logo'),
            'vat_number.required' => 'Enter your VAT registration number.',
            'document_prefixes.*.alpha_num' => 'Letters and numbers only.',
            'document_prefixes.*.required' => 'Enter a prefix.',
            'report_emails_list.*.email' => 'Check the report email addresses (separate them with commas).',
        ];
    }

    public function attributes(): array
    {
        return ['vat_rate' => 'VAT rate', 'vat_number' => 'VAT number'];
    }

    /**
     * Validated data mapped onto company_settings columns (percentages to basis points).
     *
     * @return array<string, mixed>
     */
    public function settingsData(): array
    {
        $data = $this->safe()->except(['logo', 'remove_logo', 'vat_rate', 'document_prefixes', 'adjustment_approval_value', 'report_emails', 'report_emails_list']);

        $result = [
            ...$data,
            'phone' => blank($data['phone'] ?? null) ? null : SriLankanPhone::normalize($data['phone']),
            'vat_rate_bp' => BasisPoints::fromPercent((string) $this->validated('vat_rate')),
            'document_prefixes' => collect(DocumentType::cases())
                ->mapWithKeys(fn (DocumentType $type) => [$type->value => $this->validated("document_prefixes.{$type->value}")])
                ->all(),
        ];

        if ($this->has('report_emails')) {
            $result['report_emails'] = $this->validated('report_emails_list') ?: null;
        }

        if ($this->filled('adjustment_approval_value')) {
            $result['adjustment_approval_value'] = Money::fromDecimal((string) $this->validated('adjustment_approval_value'))->cents;
        }

        return $result;
    }
}
