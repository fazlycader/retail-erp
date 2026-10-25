<?php

namespace App\Http\Controllers\Admin\Workshop;

use App\Enums\ProductionJobStatus;
use App\Enums\ProductionSource;
use App\Enums\SupplierProgress;
use App\Http\Controllers\Controller;
use App\Models\Location;
use App\Models\ProductionJob;
use App\Services\Production\ProductionService;
use App\Support\Quantity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class SupplierJobController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $canViewCosts = $user->can('view-job-costs');
        $statusFilter = (string) $request->string('status', 'open');
        $progressFilter = (string) $request->string('progress', 'all');

        $accessibleIds = Location::accessibleTo($user)->pluck('id')->all();

        $jobs = ProductionJob::query()
            ->where('source', ProductionSource::Supplier->value)
            ->where(function ($q) use ($accessibleIds) {
                $q->whereIn('location_id', $accessibleIds)
                    ->orWhereHas('salesOrder', fn ($sq) => $sq->whereIn('location_id', $accessibleIds));
            })
            ->when($statusFilter === 'open', fn ($q) => $q->open())
            ->when($statusFilter === 'ready', fn ($q) => $q->where('status', ProductionJobStatus::Ready->value))
            ->when($progressFilter !== 'all', fn ($q) => $q->where('supplier_status', $progressFilter))
            ->with([
                'salesOrder:id,number,invoice_number,customer_id,location_id',
                'salesOrder.customer:id,name',
                'location:id,name,code',
                'purchaseOrderLine.purchaseOrder.supplier:id,name',
            ])
            ->orderByRaw('due_date IS NULL')
            ->orderBy('due_date')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (ProductionJob $j) => [
                'id' => $j->id,
                'number' => $j->number,
                'title' => $j->title,
                'pieces' => $j->pieces(),
                'status' => $j->status->value,
                'status_label' => $j->status->label(),
                'supplier_status' => $j->supplier_status?->value,
                'supplier_status_label' => $j->supplier_status?->label() ?? 'Pending PO',
                'supplier_name' => $j->purchaseOrderLine?->purchaseOrder?->supplier?->name,
                'po_id' => $j->purchaseOrderLine?->purchase_order_id,
                'po_number' => $j->purchaseOrderLine?->purchaseOrder?->number,
                'received_qty' => Quantity::format((int) ($j->purchaseOrderLine?->received_qty ?? 0)),
                'ordered_qty' => Quantity::format($j->quantity),
                'due_date' => $j->due_date?->toDateString(),
                'overdue' => $j->isOverdue(),
                'customer_name' => $j->salesOrder?->customer?->name,
                'order_id' => $j->sales_order_id,
                'order_number' => $j->salesOrder?->invoice_number ?: $j->salesOrder?->number,
                'location_code' => $j->location?->code,
                ...($canViewCosts ? [
                    'unit_cost' => (int) ($j->purchaseOrderLine?->unit_cost ?? 0),
                    'total_cost' => $j->total_cost,
                ] : []),
            ]);

        return Inertia::render('Admin/Workshop/Suppliers', [
            'jobs' => $jobs,
            'filters' => [
                'status' => $statusFilter,
                'progress' => $progressFilter,
            ],
            'progressOptions' => collect(SupplierProgress::cases())->map(fn (SupplierProgress $s) => [
                'value' => $s->value,
                'label' => $s->label(),
            ])->values(),
            'editableStatuses' => collect(SupplierProgress::cases())
                ->reject(fn (SupplierProgress $s) => $s === SupplierProgress::Received)
                ->map(fn (SupplierProgress $s) => ['value' => $s->value, 'label' => $s->label()])
                ->values(),
            'canViewCosts' => $canViewCosts,
        ]);
    }

    public function updateProgress(Request $request, ProductionJob $job, ProductionService $production): RedirectResponse
    {
        $job->loadMissing('salesOrder:id,location_id');
        $user = $request->user();
        abort_unless(
            $user->can('work-on-jobs') && ($user->worksAt($job->location_id) || ($job->salesOrder && $user->worksAt($job->salesOrder->location_id))),
            403
        );

        $data = $request->validate([
            'supplier_status' => ['required', Rule::enum(SupplierProgress::class)],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $production->setSupplierStatus($job, SupplierProgress::from($data['supplier_status']), $user, $data['note'] ?? null);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['supplier_status' => $e->getMessage()]);
        }

        return back()->with('success', "Supplier progress updated for {$job->number}.");
    }
}

