<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\CashMovementCategory;
use App\Models\CompanySetting;
use App\Models\Register;
use App\Models\Shift;
use App\Support\CurrentLocation;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Registers of the current location and cash movement categories (managers), and the company-wide
 * shift settings (admins).
 */
class CashSetupController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('override');
        $settings = CompanySetting::current();

        return Inertia::render('Admin/Cash/Setup', [
            'registers' => Register::query()->where('location_id', $this->locationId($request))->orderBy('name')->get(['id', 'name', 'is_active']),
            'categories' => CashMovementCategory::query()->ordered()->get(['id', 'name', 'applies_to', 'is_active']),
            'settings' => $request->user()->role === Role::Admin ? [
                'require_shift_for_payments' => $settings->require_shift_for_payments,
                'blind_close' => $settings->blind_close,
                'variance_approval_threshold' => Money::fromCents($settings->variance_approval_threshold)->toDecimal(),
                'payout_approval_threshold' => Money::fromCents($settings->payout_approval_threshold)->toDecimal(),
            ] : null,
        ]);
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        abort_unless($request->user()->role === Role::Admin, 403);
        $data = $request->validate([
            'require_shift_for_payments' => ['boolean'],
            'blind_close' => ['boolean'],
            'variance_approval_threshold' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:1000000'],
            'payout_approval_threshold' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:10000000'],
        ]);

        $settings = CompanySetting::current();
        $before = $settings->only(['require_shift_for_payments', 'blind_close', 'variance_approval_threshold', 'payout_approval_threshold']);
        $after = [
            'require_shift_for_payments' => $request->boolean('require_shift_for_payments'),
            'blind_close' => $request->boolean('blind_close'),
            'variance_approval_threshold' => Money::fromDecimal((string) $data['variance_approval_threshold'])->cents,
            'payout_approval_threshold' => Money::fromDecimal((string) $data['payout_approval_threshold'])->cents,
        ];

        $settings->update($after);
        AuditLog::record('settings.cash', null, 'Shift & cash settings changed', $before, $after, $request->user());

        return back()->with('success', 'Shift settings saved.');
    }

    public function storeRegister(Request $request): RedirectResponse
    {
        Gate::authorize('override');
        $locationId = $this->locationId($request);
        Register::query()->create(['location_id' => $locationId, ...$this->registerData($request, $locationId)]);

        return back()->with('success', 'Register added.');
    }

    public function updateRegister(Request $request, Register $register): RedirectResponse
    {
        Gate::authorize('override');
        abort_unless($request->user()->worksAt($register->location_id), 403);
        $data = $this->registerData($request, $register->location_id, $register);

        if (! $data['is_active'] && Shift::query()->where('open_register_id', $register->id)->exists()) {
            return back()->with('error', 'Close the open shift on this register first.');
        }

        $register->update($data);

        return back()->with('success', 'Register saved.');
    }

    public function storeCategory(Request $request): RedirectResponse
    {
        Gate::authorize('override');
        CashMovementCategory::query()->create($this->categoryData($request));

        return back()->with('success', 'Category added.');
    }

    public function updateCategory(Request $request, CashMovementCategory $category): RedirectResponse
    {
        Gate::authorize('override');
        $category->update($this->categoryData($request, $category));

        return back()->with('success', 'Category saved.');
    }

    /**
     * @return array{name: string, is_active: bool}
     */
    private function registerData(Request $request, int $locationId, ?Register $register = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60', Rule::unique('registers')->where('location_id', $locationId)->ignore($register?->id)],
            'is_active' => ['boolean'],
        ]);

        return ['name' => trim($data['name']), 'is_active' => $request->boolean('is_active', true)];
    }

    /**
     * @return array<string, mixed>
     */
    private function categoryData(Request $request, ?CashMovementCategory $category = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60', Rule::unique('cash_movement_categories')->ignore($category?->id)],
            'applies_to' => ['required', 'in:any,pay_in,pay_out,cash_drop'],
            'is_active' => ['boolean'],
        ]);

        return [...$data, 'name' => trim($data['name']), 'is_active' => $request->boolean('is_active', true)];
    }

    private function locationId(Request $request): int
    {
        return CurrentLocation::id() ?? (int) $request->user()->home_location_id;
    }
}
