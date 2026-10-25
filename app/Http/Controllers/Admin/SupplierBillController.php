<?php

namespace App\Http\Controllers\Admin;

use App\Enums\SupplierBillStatus;
use App\Enums\SupplierPaymentMethod;
use App\Http\Controllers\Controller;
use App\Models\GoodsReceipt;
use App\Models\Supplier;
use App\Models\SupplierBill;
use App\Models\SupplierPayment;
use App\Services\Purchasing\SupplierBillService;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class SupplierBillController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('manage-bills');

        $user = $request->user();
        $supplierId = $request->integer('supplier_id') ?: null;
        $status = (string) $request->string('status', 'all');

        $bills = SupplierBill::query()
            ->with(['supplier:id,name', 'goodsReceipts:id,number', 'createdBy:id,name'])
            ->when($supplierId, fn ($q) => $q->where('supplier_id', $supplierId))
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->latest('id')
            ->paginate(20, ['*'], 'bills_page')
            ->withQueryString()
            ->through(fn (SupplierBill $b) => [
                'id' => $b->id,
                'number' => $b->number,
                'supplier_id' => $b->supplier_id,
                'supplier_name' => $b->supplier?->name,
                'supplier_invoice_number' => $b->supplier_invoice_number,
                'bill_date' => $b->bill_date?->toDateString(),
                'due_date' => $b->due_date?->toDateString(),
                'subtotal' => $b->subtotal,
                'tax_total' => $b->tax_total,
                'total_amount' => $b->total_amount,
                'paid_amount' => $b->paid_amount,
                'balance_due' => $b->balanceDue(),
                'status' => $b->status->value,
                'status_label' => $b->status->label(),
                'notes' => $b->notes,
                'created_by_name' => $b->createdBy?->name,
                'goods_receipts' => $b->goodsReceipts->map(fn ($g) => [
                    'id' => $g->id,
                    'number' => $g->number,
                ])->values()->all(),
            ]);

        $payments = SupplierPayment::query()
            ->with(['supplier:id,name', 'allocations.bill:id,number,supplier_invoice_number', 'createdBy:id,name'])
            ->when($supplierId, fn ($q) => $q->where('supplier_id', $supplierId))
            ->latest('id')
            ->paginate(15, ['*'], 'payments_page')
            ->withQueryString()
            ->through(fn (SupplierPayment $p) => [
                'id' => $p->id,
                'number' => $p->number,
                'supplier_id' => $p->supplier_id,
                'supplier_name' => $p->supplier?->name,
                'paid_at' => $p->paid_at?->toDateString(),
                'method' => $p->method->value,
                'method_label' => $p->method->label(),
                'amount' => $p->amount,
                'reference' => $p->reference,
                'cheque_number' => $p->cheque_number,
                'cheque_date' => $p->cheque_date?->toDateString(),
                'cheque_bank' => $p->cheque_bank,
                'notes' => $p->notes,
                'created_by_name' => $p->createdBy?->name,
                'allocations' => $p->allocations->map(fn ($a) => [
                    'id' => $a->id,
                    'bill_number' => $a->bill?->number,
                    'supplier_invoice_number' => $a->bill?->supplier_invoice_number,
                    'amount' => $a->amount,
                ])->values()->all(),
            ]);

        $unpaidBills = SupplierBill::query()
            ->whereIn('status', [
                SupplierBillStatus::Unpaid->value,
                SupplierBillStatus::PartiallyPaid->value,
            ])
            ->orderBy('due_date')
            ->orderBy('id')
            ->get()
            ->map(fn (SupplierBill $b) => [
                'id' => $b->id,
                'number' => $b->number,
                'supplier_id' => $b->supplier_id,
                'supplier_invoice_number' => $b->supplier_invoice_number,
                'due_date' => $b->due_date?->toDateString(),
                'total_amount' => $b->total_amount,
                'paid_amount' => $b->paid_amount,
                'balance_due' => $b->balanceDue(),
                'balance_due_decimal' => Money::fromCents($b->balanceDue())->toDecimal(),
            ])->values()->all();

        $recentReceipts = GoodsReceipt::query()
            ->latest('id')
            ->limit(60)
            ->get(['id', 'number', 'supplier_id', 'total_amount'])
            ->map(fn (GoodsReceipt $g) => [
                'id' => $g->id,
                'number' => $g->number,
                'supplier_id' => $g->supplier_id,
                'total_amount' => $g->total_amount,
                'total_decimal' => Money::fromCents($g->total_amount)->toDecimal(),
            ])->values()->all();

        return Inertia::render('Admin/Purchasing/Bills', [
            'bills' => $bills,
            'payments' => $payments,
            'unpaidBills' => $unpaidBills,
            'recentReceipts' => $recentReceipts,
            'suppliers' => Supplier::query()->orderBy('name')->get(['id', 'name', 'payment_terms_days']),
            'statuses' => SupplierBillStatus::options(),
            'paymentMethods' => SupplierPaymentMethod::options(),
            'filters' => [
                'supplier_id' => $supplierId,
                'status' => $status,
            ],
            'canCreatePo' => $user->can('manage-purchase-orders'),
            'canReceive' => $user->can('receive-stock'),
            'canManageBills' => true,
            'canViewCost' => $user->can('view-cost'),
        ]);
    }

    public function storeBill(Request $request, SupplierBillService $service): RedirectResponse
    {
        Gate::authorize('manage-bills');

        $validated = $request->validate([
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'supplier_invoice_number' => ['required', 'string', 'max:120'],
            'bill_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date'],
            'subtotal' => ['required', 'string'],
            'tax_total' => ['nullable', 'string'],
            'goods_receipt_ids' => ['nullable', 'array'],
            'goods_receipt_ids.*' => ['integer', 'exists:goods_receipts,id'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $subtotalCents = Money::fromDecimal($validated['subtotal'])->cents;
            $taxCents = isset($validated['tax_total']) && trim((string) $validated['tax_total']) !== ''
                ? Money::fromDecimal($validated['tax_total'])->cents
                : 0;

            $bill = $service->createBill([
                'supplier_id' => (int) $validated['supplier_id'],
                'supplier_invoice_number' => $validated['supplier_invoice_number'],
                'bill_date' => $validated['bill_date'] ?? null,
                'due_date' => $validated['due_date'] ?? null,
                'subtotal' => $subtotalCents,
                'tax_total' => $taxCents,
                'total_amount' => $subtotalCents + $taxCents,
                'goods_receipt_ids' => $validated['goods_receipt_ids'] ?? [],
                'notes' => $validated['notes'] ?? null,
            ], $request->user());
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['supplier_invoice_number' => $e->getMessage()]);
        }

        return back()->with('success', "Supplier bill {$bill->number} recorded.");
    }

    public function storePayment(Request $request, SupplierBillService $service): RedirectResponse
    {
        Gate::authorize('manage-bills');

        $validated = $request->validate([
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'paid_at' => ['nullable', 'date'],
            'method' => ['required', Rule::enum(SupplierPaymentMethod::class)],
            'reference' => ['nullable', 'string', 'max:160'],
            'cheque_number' => ['nullable', 'string', 'max:80'],
            'cheque_date' => ['nullable', 'date'],
            'cheque_bank' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'allocations' => ['required', 'array', 'min:1'],
            'allocations.*.supplier_bill_id' => ['required', 'integer', 'exists:supplier_bills,id'],
            'allocations.*.amount' => ['required', 'string'],
        ]);

        try {
            $allocations = [];
            foreach ($validated['allocations'] as $alloc) {
                $cents = Money::fromDecimal($alloc['amount'])->cents;
                if ($cents <= 0) {
                    continue;
                }
                $allocations[] = [
                    'supplier_bill_id' => (int) $alloc['supplier_bill_id'],
                    'amount' => $cents,
                ];
            }

            $payment = $service->recordPayment([
                'supplier_id' => (int) $validated['supplier_id'],
                'paid_at' => $validated['paid_at'] ?? null,
                'method' => $validated['method'],
                'reference' => $validated['reference'] ?? null,
                'cheque_number' => $validated['cheque_number'] ?? null,
                'cheque_date' => $validated['cheque_date'] ?? null,
                'cheque_bank' => $validated['cheque_bank'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'allocations' => $allocations,
            ], $request->user());
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['allocations' => $e->getMessage()]);
        }

        return back()->with('success', "Supplier payment {$payment->number} recorded.");
    }
}
