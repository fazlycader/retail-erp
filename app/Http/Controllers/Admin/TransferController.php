<?php

namespace App\Http\Controllers\Admin;

use App\Enums\TransferStatus;
use App\Exceptions\InsufficientStock;
use App\Http\Controllers\Controller;
use App\Models\Location;
use App\Models\Transfer;
use App\Models\TransferLine;
use App\Services\Inventory\TransferService;
use App\Support\CurrentLocation;
use App\Support\Quantity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class TransferController extends Controller
{
    public function index(Request $request, CurrentLocation $currentLocation): Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', 'max:30'],
        ]);

        $user = $request->user();
        $visibleLocIds = $currentLocation->visibleIdsFor($user);

        $baseQuery = Transfer::query()
            ->when($visibleLocIds !== null, function ($q) use ($visibleLocIds) {
                $q->where(function ($sub) use ($visibleLocIds) {
                    $sub->whereIn('from_location_id', $visibleLocIds)
                        ->orWhereIn('to_location_id', $visibleLocIds);
                });
            });

        $inTransitCount = (clone $baseQuery)
            ->where('status', TransferStatus::Dispatched)
            ->count();

        $discrepancyCount = (clone $baseQuery)
            ->where('status', TransferStatus::PartiallyReceived)
            ->count();

        $transfers = (clone $baseQuery)
            ->with(['fromLocation:id,name,code', 'toLocation:id,name,code', 'requester:id,name', 'dispatcher:id,name', 'receiver:id,name'])
            ->withCount('lines')
            ->when($filters['status'] ?? null, function ($q, $status) {
                if ($status === 'in_transit') {
                    $q->where('status', TransferStatus::Dispatched->value);
                } else {
                    $q->where('status', $status);
                }
            })
            ->when($filters['search'] ?? null, function ($q, $search) {
                $term = trim($search);
                $q->where(function ($sub) use ($term) {
                    $sub->where('number', 'like', "%{$term}%")
                        ->orWhere('vehicle_note', 'like', "%{$term}%")
                        ->orWhereHas('lines.variant', fn ($vq) => $vq->where('sku', 'like', "%{$term}%"));
                });
            })
            ->latest('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Transfer $t) => [
                'id' => $t->id,
                'number' => $t->number,
                'from_location' => $t->fromLocation ? ['id' => $t->fromLocation->id, 'name' => $t->fromLocation->name, 'code' => $t->fromLocation->code] : null,
                'to_location' => $t->toLocation ? ['id' => $t->toLocation->id, 'name' => $t->toLocation->name, 'code' => $t->toLocation->code] : null,
                'status' => $t->status->value,
                'status_label' => $t->status->label(),
                'lines_count' => $t->lines_count,
                'vehicle_note' => $t->vehicle_note,
                'requested_by_name' => $t->requester?->name,
                'dispatched_by_name' => $t->dispatcher?->name,
                'received_by_name' => $t->receiver?->name,
                'dispatched_at' => $t->dispatched_at?->toIso8601String(),
                'received_at' => $t->received_at?->toIso8601String(),
                'created_at' => $t->created_at?->toIso8601String(),
            ]);

        return Inertia::render('Admin/Transfers/Index', [
            'transfers' => $transfers,
            'inTransitCount' => $inTransitCount,
            'discrepancyCount' => $discrepancyCount,
            'statuses' => TransferStatus::options(),
            'filters' => [
                'search' => $filters['search'] ?? '',
                'status' => $filters['status'] ?? '',
            ],
        ]);
    }

    public function create(Request $request, CurrentLocation $currentLocation): Response
    {
        $user = $request->user();
        $allLocations = Location::query()->where('is_active', true)->orderBy('id')->get(['id', 'name', 'code']);
        $accessibleIds = Location::accessibleTo($user)->pluck('id')->all();

        return Inertia::render('Admin/Transfers/Form', [
            'locations' => $allLocations->map(fn (Location $l) => [
                'id' => $l->id,
                'name' => $l->name,
                'code' => $l->code,
                'accessible' => in_array($l->id, $accessibleIds, true),
            ])->values()->all(),
            'defaultFromId' => $currentLocation->id() ?? $accessibleIds[0] ?? null,
        ]);
    }

    public function store(Request $request, TransferService $service): RedirectResponse
    {
        $validated = $request->validate([
            'from_location_id' => ['required', 'integer', 'exists:locations,id'],
            'to_location_id' => ['required', 'integer', 'exists:locations,id', 'different:from_location_id'],
            'status' => ['nullable', Rule::in([TransferStatus::Draft->value, TransferStatus::Requested->value])],
            'vehicle_note' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_variant_id' => ['required', 'integer', 'exists:product_variants,id'],
            'lines.*.requested_qty' => ['required', 'string'],
            'lines.*.note' => ['nullable', 'string', 'max:255'],
        ]);

        $user = $request->user();
        if (! $user->worksAt((int) $validated['from_location_id']) && ! $user->worksAt((int) $validated['to_location_id'])) {
            abort(403);
        }

        try {
            $lines = array_map(fn (array $l) => [
                'product_variant_id' => (int) $l['product_variant_id'],
                'requested_qty' => Quantity::parse($l['requested_qty']),
                'note' => $l['note'] ?? null,
            ], $validated['lines']);

            $transfer = $service->create([
                'from_location_id' => (int) $validated['from_location_id'],
                'to_location_id' => (int) $validated['to_location_id'],
                'status' => $validated['status'] ?? TransferStatus::Requested->value,
                'vehicle_note' => $validated['vehicle_note'] ?? null,
                'note' => $validated['note'] ?? null,
                'lines' => $lines,
            ], $user);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['lines' => $e->getMessage()]);
        }

        return redirect()->route('transfers.show', $transfer)
            ->with('success', "Transfer {$transfer->number} created.");
    }

    public function show(Request $request, Transfer $transfer): Response
    {
        $user = $request->user();
        if (! $user->worksAt($transfer->from_location_id) && ! $user->worksAt($transfer->to_location_id)) {
            abort(403);
        }

        $canViewCost = $user->can('view-cost');
        $transfer->load(['fromLocation:id,name,code', 'toLocation:id,name,code', 'requester:id,name', 'dispatcher:id,name', 'receiver:id,name', 'closer:id,name', 'lines.variant.product', 'lines.stockUnit:id,serial_number']);

        return Inertia::render('Admin/Transfers/Show', [
            'transfer' => [
                'id' => $transfer->id,
                'number' => $transfer->number,
                'from_location' => $transfer->fromLocation ? ['id' => $transfer->fromLocation->id, 'name' => $transfer->fromLocation->name, 'code' => $transfer->fromLocation->code] : null,
                'to_location' => $transfer->toLocation ? ['id' => $transfer->toLocation->id, 'name' => $transfer->toLocation->name, 'code' => $transfer->toLocation->code] : null,
                'status' => $transfer->status->value,
                'status_label' => $transfer->status->label(),
                'vehicle_note' => $transfer->vehicle_note,
                'note' => $transfer->note,
                'resolution' => $transfer->resolution,
                'requested_by_name' => $transfer->requester?->name,
                'dispatched_by_name' => $transfer->dispatcher?->name,
                'received_by_name' => $transfer->receiver?->name,
                'closed_by_name' => $transfer->closer?->name,
                'requested_at' => $transfer->requested_at?->toIso8601String(),
                'dispatched_at' => $transfer->dispatched_at?->toIso8601String(),
                'received_at' => $transfer->received_at?->toIso8601String(),
                'closed_at' => $transfer->closed_at?->toIso8601String(),
                'can_dispatch' => in_array($transfer->status, [TransferStatus::Draft, TransferStatus::Requested], true) && $user->worksAt($transfer->from_location_id),
                'can_receive' => $transfer->status === TransferStatus::Dispatched && $user->worksAt($transfer->to_location_id),
                'can_resolve' => $transfer->status === TransferStatus::PartiallyReceived && $user->role->canOverride(),
                'can_cancel' => in_array($transfer->status, [TransferStatus::Draft, TransferStatus::Requested], true) && ($user->worksAt($transfer->from_location_id) || $user->worksAt($transfer->to_location_id)),
                'lines' => $transfer->lines->map(fn (TransferLine $l) => [
                    'id' => $l->id,
                    'product_variant_id' => $l->product_variant_id,
                    'sku' => $l->variant?->sku,
                    'barcode' => $l->variant?->barcode,
                    'product_name' => $l->variant?->product?->name,
                    'variant_name' => $l->variant?->name,
                    'serial_number' => $l->stockUnit?->serial_number,
                    'requested_qty' => $l->requested_qty,
                    'requested_decimal' => Quantity::toDecimal($l->requested_qty),
                    'sent_qty' => $l->sent_qty,
                    'sent_decimal' => Quantity::toDecimal($l->sent_qty ?: $l->requested_qty),
                    'received_qty' => $l->received_qty,
                    'received_decimal' => Quantity::toDecimal($l->received_qty ?: $l->sent_qty),
                    'discrepancy_qty' => $l->discrepancyQty(),
                    'discrepancy_reason' => $l->discrepancy_reason,
                    'note' => $l->note,
                    ...($canViewCost ? ['unit_cost' => $l->unit_cost] : []),
                ])->values()->all(),
            ],
            'canViewCost' => $canViewCost,
        ]);
    }

    public function submitRequest(Request $request, Transfer $transfer, TransferService $service): RedirectResponse
    {
        if (! $request->user()->worksAt($transfer->from_location_id) && ! $request->user()->worksAt($transfer->to_location_id)) {
            abort(403);
        }

        try {
            $service->submitRequest($transfer, $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Transfer {$transfer->number} requested.");
    }

    public function dispatch(Request $request, Transfer $transfer, TransferService $service): RedirectResponse
    {
        if (! $request->user()->worksAt($transfer->from_location_id)) {
            abort(403);
        }

        $validated = $request->validate([
            'vehicle_note' => ['nullable', 'string', 'max:255'],
            'lines' => ['required', 'array'],
            'lines.*.id' => ['required', 'integer'],
            'lines.*.sent_qty' => ['required', 'string'],
        ]);

        try {
            $sentByLine = [];
            foreach ($validated['lines'] as $l) {
                $sentByLine[(int) $l['id']] = Quantity::parse($l['sent_qty']);
            }

            $service->dispatch($transfer, $request->user(), $sentByLine, $validated['vehicle_note'] ?? null);
        } catch (InsufficientStock|InvalidArgumentException $e) {
            throw ValidationException::withMessages(['lines' => $e->getMessage()]);
        }

        return back()->with('success', "Transfer {$transfer->number} dispatched and is now in transit.");
    }

    public function receive(Request $request, Transfer $transfer, TransferService $service): RedirectResponse
    {
        if (! $request->user()->worksAt($transfer->to_location_id)) {
            abort(403);
        }

        $validated = $request->validate([
            'lines' => ['required', 'array'],
            'lines.*.id' => ['required', 'integer'],
            'lines.*.received_qty' => ['required', 'string'],
            'lines.*.discrepancy_reason' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $receivedByLine = [];
            foreach ($validated['lines'] as $l) {
                $receivedByLine[(int) $l['id']] = [
                    'received_qty' => Quantity::parse($l['received_qty']),
                    'discrepancy_reason' => $l['discrepancy_reason'] ?? null,
                ];
            }

            $updated = $service->receive($transfer, $request->user(), $receivedByLine);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['lines' => $e->getMessage()]);
        }

        $msg = $updated->status === TransferStatus::PartiallyReceived
            ? "Transfer {$transfer->number} partially received. A manager must resolve the discrepancy."
            : "Transfer {$transfer->number} received in full.";

        return back()->with('success', $msg);
    }

    public function resolve(Request $request, Transfer $transfer, TransferService $service): RedirectResponse
    {
        if (! $request->user()->role->canOverride()) {
            abort(403);
        }

        $validated = $request->validate([
            'resolution' => ['required', Rule::in(['write_off', 'return_to_source'])],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $service->resolveDiscrepancy($transfer, $request->user(), $validated['resolution'], $validated['note'] ?? null);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['resolution' => $e->getMessage()]);
        }

        return back()->with('success', "Discrepancy on {$transfer->number} resolved and transfer closed.");
    }

    public function cancel(Request $request, Transfer $transfer, TransferService $service): RedirectResponse
    {
        if (! $request->user()->worksAt($transfer->from_location_id) && ! $request->user()->worksAt($transfer->to_location_id)) {
            abort(403);
        }

        try {
            $service->cancel($transfer, $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Transfer {$transfer->number} cancelled.");
    }
}
