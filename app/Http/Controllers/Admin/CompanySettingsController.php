<?php

namespace App\Http\Controllers\Admin;

use App\Enums\DocumentType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateCompanySettingsRequest;
use App\Models\AuditLog;
use App\Models\CompanySetting;
use App\Rules\SriLankanPhone;
use App\Support\ImageUploader;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class CompanySettingsController extends Controller
{
    public function edit(): Response
    {
        $company = CompanySetting::current();

        return Inertia::render('Admin/Settings/Company', [
            'settings' => [
                'name' => $company->name,
                'logo_url' => $company->logoUrl(),
                'address' => $company->address,
                'phone' => SriLankanPhone::display($company->phone),
                'email' => $company->email,
                'vat_registered' => $company->vat_registered,
                'vat_number' => $company->vat_number,
                'vat_rate' => $company->vatRatePercent(),
                'prices_include_vat' => $company->prices_include_vat,
                'allow_negative_stock' => $company->allow_negative_stock,
                'managers_edit_catalog' => $company->managers_edit_catalog,
                'adjustment_approval_value' => Money::fromCents($company->adjustment_approval_value)->toDecimal(),
                'return_days' => $company->return_days ?? 7,
                'credit_note_expiry_days' => $company->credit_note_expiry_days ?? 365,
                'managers_see_profit' => $company->managers_see_profit,
                'report_emails' => implode(', ', $company->report_emails ?? []),
                'daily_email_time' => $company->daily_email_time ?? '07:00',
                'document_prefixes' => $company->prefixes(),
            ],
            'documentTypes' => collect(DocumentType::cases())->map(fn (DocumentType $type) => ['value' => $type->value, 'label' => $type->label()])->all(),
            'timezone' => config('app.timezone'),
            'currency' => config('app.currency'),
        ]);
    }

    public function update(UpdateCompanySettingsRequest $request, ImageUploader $images): RedirectResponse
    {
        $company = CompanySetting::current();
        $company->fill($request->settingsData());

        if ($request->hasFile('logo') || $request->boolean('remove_logo')) {
            if ($company->logo_path) {
                Storage::disk('public')->delete($company->logo_path);
            }

            $company->logo_path = $request->hasFile('logo') ? $images->storeLogo($request->file('logo')) : null;
        }

        $changed = array_keys($company->getDirty());
        $before = array_intersect_key($company->getOriginal(), array_flip($changed));
        $company->save();

        if ($changed !== []) {
            AuditLog::record('settings.company_updated', null, 'Company settings changed ('.implode(', ', $changed).')', $before, $company->only($changed));
        }

        return back()->with('success', 'Settings saved.');
    }
}
