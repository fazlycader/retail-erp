<?php

use App\Enums\DocumentType;
use App\Models\AuditLog;
use App\Models\CompanySetting;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

function companyPayload(array $overrides = []): array
{
    return [
        'name' => 'Lanka Home Centre',
        'address' => '123 Galle Road, Colombo 03',
        'phone' => '011 234 5678',
        'email' => 'info@example.com',
        'vat_registered' => true,
        'vat_number' => '123456789-7000',
        'vat_rate' => '18',
        'prices_include_vat' => true,
        'allow_negative_stock' => false,
        'document_prefixes' => DocumentType::defaultPrefixes(),
        ...$overrides,
    ];
}

test('only admins can open company settings', function () {
    $this->actingAs(User::factory()->manager()->create())->get(route('settings.edit'))->assertForbidden();

    $this->actingAs(User::factory()->admin()->create())->get(route('settings.edit'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Settings/Company')
            ->where('timezone', 'Asia/Colombo')
            ->where('settings.document_prefixes.invoice', 'INV'));
});

test('an admin can save company settings and a logo', function () {
    Storage::fake('public');

    $this->actingAs(User::factory()->admin()->create())
        ->post(route('settings.update'), companyPayload([
            'vat_rate' => '18.5',
            'allow_negative_stock' => true,
            'document_prefixes' => [...DocumentType::defaultPrefixes(), 'invoice' => 'tax'],
            'logo' => UploadedFile::fake()->image('logo.png', 800, 800),
        ]))
        ->assertSessionHasNoErrors();

    CompanySetting::forget();
    $company = CompanySetting::current();

    expect($company->name)->toBe('Lanka Home Centre')
        ->and($company->phone)->toBe('+94112345678')
        ->and($company->vat_rate_bp)->toBe(1850)
        ->and($company->allow_negative_stock)->toBeTrue()
        ->and($company->prefixFor(DocumentType::Invoice))->toBe('TAX')
        ->and($company->logo_path)->not->toBeNull()
        ->and(AuditLog::where('action', 'settings.company_updated')->exists())->toBeTrue();

    Storage::disk('public')->assertExists($company->logo_path);
});

test('a VAT registered company needs a VAT number', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->post(route('settings.update'), companyPayload(['vat_number' => '']))
        ->assertSessionHasErrors('vat_number');
});

test('document prefixes must be different and alphanumeric', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->post(route('settings.update'), companyPayload(['document_prefixes' => [...DocumentType::defaultPrefixes(), 'quotation' => 'INV', 'grn' => 'G-R']]))
        ->assertSessionHasErrors(['document_prefixes.quotation', 'document_prefixes.grn']);
});
