<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PurchaseOrderStatus;
use App\Enums\SupplierBillStatus;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Supplier;
use App\Support\Money;
use App\Support\SyncVersion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class SupplierController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $canManage = $user->can('manage-suppliers');
        $canViewCost = $user->can('view-cost');
        $search = trim((string) $request->string('search'));
        $status = (string) $request->string('status', 'all');

        $query = Supplier::query()
            ->withCount([
                'purchaseOrders as open_pos_count' => fn ($q) => $q->whereIn('status', [
                    PurchaseOrderStatus::Draft->value,
                    PurchaseOrderStatus::Sent->value,
                    PurchaseOrderStatus::PartiallyReceived->value,
                ]),
                'goodsReceipts',
            ])
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($sub) use ($search) {
                    $sub->where('name', 'like', "%{$search}%")
                        ->orWhere('contact_person', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('vat_number', 'like', "%{$search}%");
                });
            })
            ->when($status === 'active', fn ($q) => $q->where('is_active', true))
            ->when($status === 'inactive', fn ($q) => $q->where('is_active', false))
            ->orderBy('name');

        $suppliers = $query->paginate(20)->withQueryString();

        $unpaidBySupplier = [];
        if ($canViewCost && $suppliers->isNotEmpty()) {
            $unpaidRows = DB::table('supplier_bills')
                ->whereIn('supplier_id', $suppliers->pluck('id')->all())
                ->whereIn('status', [
                    SupplierBillStatus::Unpaid->value,
                    SupplierBillStatus::PartiallyPaid->value,
                ])
                ->groupBy('supplier_id')
                ->selectRaw('supplier_id, SUM(total_amount - paid_amount) as balance_due')
                ->get();

            foreach ($unpaidRows as $row) {
                $unpaidBySupplier[(int) $row->supplier_id] = (int) $row->balance_due;
            }
        }

        $suppliers->through(fn (Supplier $s) => [
            'id' => $s->id,
            'name' => $s->name,
            'contact_person' => $s->contact_person,
            'phone' => $s->phone,
            'secondary_phone' => $s->secondary_phone,
            'email' => $s->email,
            'address' => $s->address,
            'payment_terms_days' => $s->payment_terms_days,
            'vat_number' => $s->vat_number,
            'notes' => $s->notes,
            'is_active' => $s->is_active,
            'open_pos_count' => $s->open_pos_count,
            'goods_receipts_count' => $s->goods_receipts_count,
            ...($canViewCost ? [
                'credit_limit' => $s->credit_limit,
                'bank_details' => $s->bank_details,
                'balance_due' => $unpaidBySupplier[$s->id] ?? 0,
            ] : []),
        ]);

        return Inertia::render('Admin/Suppliers/Index', [
            'suppliers' => $suppliers,
            'filters' => [
                'search' => $search,
                'status' => $status,
            ],
            'canManage' => $canManage,
            'canViewCost' => $canViewCost,
            'canManageBills' => $user->can('manage-bills'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('manage-suppliers');

        $validated = $this->validateSupplier($request);

        $supplier = DB::transaction(function () use ($validated, $request) {
            $creditLimit = isset($validated['credit_limit']) && trim((string) $validated['credit_limit']) !== ''
                ? Money::fromDecimal((string) $validated['credit_limit'])->cents
                : 0;

            $supplier = Supplier::query()->create([
                'name' => trim($validated['name']),
                'contact_person' => $this->nullableTrim($validated['contact_person'] ?? null),
                'phone' => $this->nullableTrim($validated['phone'] ?? null),
                'secondary_phone' => $this->nullableTrim($validated['secondary_phone'] ?? null),
                'email' => $this->nullableTrim($validated['email'] ?? null),
                'address' => $this->nullableTrim($validated['address'] ?? null),
                'payment_terms_days' => max(0, (int) ($validated['payment_terms_days'] ?? 30)),
                'credit_limit' => $creditLimit,
                'bank_details' => $this->nullableTrim($validated['bank_details'] ?? null),
                'vat_number' => $this->nullableTrim($validated['vat_number'] ?? null),
                'notes' => $this->nullableTrim($validated['notes'] ?? null),
                'is_active' => (bool) ($validated['is_active'] ?? true),
                'sync_version' => 0,
            ]);

            SyncVersion::bump($supplier);

            AuditLog::record(
                'supplier.created',
                $supplier,
                "Created supplier {$supplier->name}",
                null,
                ['name' => $supplier->name, 'payment_terms_days' => $supplier->payment_terms_days],
                $request->user(),
            );

            return $supplier;
        });

        return back()->with('success', "Supplier {$supplier->name} created.");
    }

    public function update(Request $request, Supplier $supplier): RedirectResponse
    {
        Gate::authorize('manage-suppliers');

        $validated = $this->validateSupplier($request);

        DB::transaction(function () use ($validated, $supplier, $request) {
            $creditLimit = isset($validated['credit_limit']) && trim((string) $validated['credit_limit']) !== ''
                ? Money::fromDecimal((string) $validated['credit_limit'])->cents
                : 0;

            $supplier->update([
                'name' => trim($validated['name']),
                'contact_person' => $this->nullableTrim($validated['contact_person'] ?? null),
                'phone' => $this->nullableTrim($validated['phone'] ?? null),
                'secondary_phone' => $this->nullableTrim($validated['secondary_phone'] ?? null),
                'email' => $this->nullableTrim($validated['email'] ?? null),
                'address' => $this->nullableTrim($validated['address'] ?? null),
                'payment_terms_days' => max(0, (int) ($validated['payment_terms_days'] ?? 30)),
                'credit_limit' => $creditLimit,
                'bank_details' => $this->nullableTrim($validated['bank_details'] ?? null),
                'vat_number' => $this->nullableTrim($validated['vat_number'] ?? null),
                'notes' => $this->nullableTrim($validated['notes'] ?? null),
                'is_active' => (bool) ($validated['is_active'] ?? $supplier->is_active),
            ]);

            SyncVersion::bump($supplier);

            AuditLog::record(
                'supplier.updated',
                $supplier,
                "Updated supplier {$supplier->name}",
                null,
                ['name' => $supplier->name, 'payment_terms_days' => $supplier->payment_terms_days],
                $request->user(),
            );
        });

        return back()->with('success', "Supplier {$supplier->name} updated.");
    }

    public function toggleActive(Request $request, Supplier $supplier): RedirectResponse
    {
        Gate::authorize('manage-suppliers');

        $supplier->update(['is_active' => ! $supplier->is_active]);
        SyncVersion::bump($supplier);

        AuditLog::record(
            'supplier.toggled',
            $supplier,
            ($supplier->is_active ? 'Activated' : 'Deactivated')." supplier {$supplier->name}",
            null,
            ['is_active' => $supplier->is_active],
            $request->user(),
        );

        return back()->with('success', "Supplier {$supplier->name} ".($supplier->is_active ? 'activated' : 'deactivated').'.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validateSupplier(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'contact_person' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:40'],
            'secondary_phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:160'],
            'address' => ['nullable', 'string', 'max:1000'],
            'payment_terms_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'credit_limit' => ['nullable', 'string', 'max:40'],
            'bank_details' => ['nullable', 'string', 'max:2000'],
            'vat_number' => ['nullable', 'string', 'max:60'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }

    private function nullableTrim(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
