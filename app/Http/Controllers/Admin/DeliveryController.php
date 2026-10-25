<?php

namespace App\Http\Controllers\Admin;

use App\Enums\DeliveryStatus;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Delivery;
use App\Models\DeliveryRun;
use App\Models\DeliveryVehicle;
use App\Models\DeliveryZone;
use App\Models\DriverCollection;
use App\Models\InstallationJob;
use App\Models\Location;
use App\Models\SalesOrder;
use App\Models\Shift;
use App\Models\User;
use App\Services\Delivery\DeliveryService;
use App\Support\CurrentLocation;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class DeliveryController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $date = $request->date('date')?->toDateString() ?? today()->toDateString();
        $location = CurrentLocation::get();
        $locations = Location::accessibleTo($user)->get()->map(fn ($l) => ['id' => $l->id, 'name' => $l->name, 'code' => $l->code]);
        $accessibleLocationIds = $locations->pluck('id')->all();
        $deliveries = Delivery::query()->with(['salesOrder.customer:id,name', 'zone:id,name', 'lines.variant.product:id,name,width_mm,depth_mm,height_mm', 'stops.run.vehicle:id,name,registration', 'stops.run.driver:id,name'])
            ->whereDate('scheduled_date', $date)
            ->when($location, fn ($q) => $q->where('location_id', $location->id), fn ($q) => $q->whereIn('location_id', $accessibleLocationIds))
            ->orderBy('scheduled_date')->orderBy('time_window')->orderBy('id')->get();
        $runs = DeliveryRun::query()->with(['vehicle:id,name,registration', 'driver:id,name', 'stops.delivery.salesOrder.customer'])
            ->whereDate('run_date', $date)
            ->when($location, fn ($q) => $q->where('location_id', $location->id), fn ($q) => $q->whereIn('location_id', $accessibleLocationIds))
            ->orderBy('id')->get();
        $orders = SalesOrder::query()->with('customer:id,name')->whereIn('location_id', $accessibleLocationIds)
            ->where('fulfilment_type', 'deliver')->whereIn('status', ['confirmed', 'in_production', 'ready', 'partially_fulfilled'])
            ->whereDoesntHave('deliveries', fn ($q) => $q->whereNotIn('status', [DeliveryStatus::Failed->value, DeliveryStatus::Delivered->value]))
            ->orderBy('requested_delivery_date')->limit(100)->get()
            ->map(fn ($o) => ['id' => $o->id, 'number' => $o->invoice_number ?: $o->number, 'customer' => $o->customer?->name, 'requested_date' => $o->requested_delivery_date?->toDateString(), 'address' => collect($o->delivery_address ?? [])->filter()->implode(', '), 'balance' => $o->balance_due, 'grand_total' => $o->grand_total]);
        $heldCollections = DriverCollection::query()->with(['driver:id,name', 'delivery:id,number,location_id', 'payment.method:id,name,code'])
            ->where('status', 'held_by_driver')->whereHas('delivery', fn ($q) => $q->whereIn('location_id', $accessibleLocationIds))->orderBy('id')->get();
        $shifts = Shift::query()->open()->whereIn('location_id', $accessibleLocationIds)->with('user:id,name')->get()
            ->map(fn ($s) => ['id' => $s->id, 'user' => $s->user?->name, 'date' => $s->business_date->toDateString(), 'location_id' => $s->location_id]);

        return Inertia::render('Admin/Deliveries/Index', [
            'date' => $date, 'location' => $location?->toOption(), 'locations' => $locations,
            'deliveries' => $deliveries->map(fn (Delivery $d) => $this->present($d)),
            'runs' => $runs->map(fn (DeliveryRun $r) => ['id' => $r->id, 'number' => $r->number, 'status' => $r->status, 'run_date' => $r->run_date->toDateString(), 'start_odometer' => $r->start_odometer, 'end_odometer' => $r->end_odometer, 'vehicle' => $r->vehicle?->name.' · '.$r->vehicle?->registration, 'driver' => $r->driver?->name, 'stops' => $r->stops->map(fn ($s) => ['id' => $s->id, 'delivery_id' => $s->delivery_id, 'number' => $s->delivery?->number, 'customer' => $s->delivery?->salesOrder?->customer?->name, 'sort_order' => $s->sort_order])->values()]),
            'orders' => $orders, 'zones' => DeliveryZone::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'lead_days', 'fee_rule', 'fee_cents', 'free_above_cents']),
            'allZones' => DeliveryZone::query()->orderBy('name')->get(['id', 'name', 'fee_rule', 'fee_cents', 'free_above_cents', 'lead_days', 'areas', 'is_active']),
            'vehicles' => DeliveryVehicle::query()->where('is_active', true)->where(fn ($q) => $q->whereNull('location_id')->orWhereIn('location_id', $accessibleLocationIds))->get(['id', 'name', 'registration', 'capacity_items', 'capacity_cubic_mm', 'odometer']),
            'drivers' => User::query()->active()->where('role', Role::Driver->value)->whereIn('id', DB::table('location_user')->whereIn('location_id', $accessibleLocationIds)->pluck('user_id'))->orderBy('name')->get(['id', 'name']),
            'heldCollections' => $heldCollections->map(fn ($c) => ['id' => $c->id, 'driver' => $c->driver?->name, 'delivery' => $c->delivery?->number, 'amount' => $c->amount, 'method' => $c->payment?->method?->name, 'reference' => $c->reference]),
            'shifts' => $shifts,
            'installations' => InstallationJob::query()->with(['salesOrder.customer:id,name'])->whereDate('scheduled_date', $date)->whereIn('location_id', $accessibleLocationIds)->orderBy('id')->get()->map(fn ($j) => ['id' => $j->id, 'customer' => $j->salesOrder?->customer?->name, 'order' => $j->salesOrder?->invoice_number ?: $j->salesOrder?->number, 'status' => $j->status, 'checklist' => $j->checklist ?? [], 'photos' => $j->completion_photos ?? []]),
            'canManage' => $user->role->canOverride(),
        ]);
    }

    public function store(Request $request, DeliveryService $service): RedirectResponse
    {
        $data = $request->validate(['sales_order_id' => ['required', 'integer', 'exists:sales_orders,id'], 'delivery_zone_id' => ['nullable', 'integer', 'exists:delivery_zones,id'], 'scheduled_date' => ['required', 'date'], 'time_window' => ['nullable', 'string', 'max:80'], 'address' => ['nullable', 'string', 'max:500'], 'contact_name' => ['nullable', 'string', 'max:160'], 'contact_phone' => ['nullable', 'string', 'max:24'], 'requires_installation' => ['boolean']]);
        $orderLocation = SalesOrder::query()->whereKey($data['sales_order_id'])->value('location_id');
        abort_unless($orderLocation && $request->user()->worksAt((int) $orderLocation), 403);
        try {
            $service->createForOrder(SalesOrder::query()->findOrFail($data['sales_order_id']), $data, $request->user());
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['sales_order_id' => $e->getMessage()]);
        }

        return back()->with('success', 'Delivery scheduled.');
    }

    public function createRun(Request $request, DeliveryService $service): RedirectResponse
    {
        $data = $request->validate(['location_id' => ['required', 'integer', 'exists:locations,id'], 'loading_location_id' => ['nullable', 'integer', 'exists:locations,id'], 'vehicle_id' => ['required', 'integer', 'exists:delivery_vehicles,id'], 'driver_id' => ['required', 'integer', 'exists:users,id'], 'run_date' => ['required', 'date'], 'delivery_ids' => ['required', 'array', 'min:1'], 'delivery_ids.*' => ['integer', 'exists:deliveries,id'], 'helper_names' => ['nullable', 'string', 'max:255'], 'override_capacity' => ['boolean']]);
        abort_unless($request->user()->worksAt((int) $data['location_id']) && $request->user()->worksAt((int) ($data['loading_location_id'] ?? $data['location_id'])), 403);
        try {
            $service->createRun($data, $request->user(), $request->user()->role->canOverride() && ($data['override_capacity'] ?? false));
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['delivery_ids' => $e->getMessage()]);
        }

        return back()->with('success', 'Delivery run planned.');
    }

    public function storeZone(Request $request): RedirectResponse
    {
        abort_unless($request->user()->role->canOverride(), 403);
        $data = $request->validate(['name' => ['required', 'string', 'max:100'], 'areas' => ['nullable', 'string', 'max:1000'], 'fee_rule' => ['required', Rule::in(['flat', 'order_value', 'free_above'])], 'fee_cents' => ['required', 'integer', 'min:0'], 'free_above_cents' => ['nullable', 'integer', 'min:0'], 'lead_days' => ['required', 'integer', 'min:0', 'max:60']]);
        DeliveryZone::query()->create([...$data, 'areas' => array_values(array_filter(array_map('trim', explode(',', $data['areas'] ?? '')))), 'is_active' => true]);

        return back()->with('success', 'Delivery zone added.');
    }

    public function storeVehicle(Request $request): RedirectResponse
    {
        abort_unless($request->user()->role->canOverride(), 403);
        $data = $request->validate(['location_id' => ['nullable', 'integer', 'exists:locations,id'], 'name' => ['required', 'string', 'max:100'], 'registration' => ['required', 'string', 'max:24', 'unique:delivery_vehicles,registration'], 'capacity_items' => ['nullable', 'integer', 'min:1'], 'capacity_cubic_mm' => ['nullable', 'integer', 'min:1'], 'odometer' => ['required', 'integer', 'min:0']]);
        abort_unless(empty($data['location_id']) || $request->user()->worksAt((int) $data['location_id']), 403);
        DeliveryVehicle::query()->create($data + ['is_active' => true]);

        return back()->with('success', 'Delivery vehicle added.');
    }

    public function loadRun(Request $request, DeliveryRun $run, DeliveryService $service): RedirectResponse
    {
        abort_unless($request->user()->worksAt($run->location_id), 403);
        $request->validate(['start_odometer' => ['required', 'integer', 'min:0']]);
        try {
            $service->loadRun($run, (int) $request->integer('start_odometer'), $request->user());
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['start_odometer' => $e->getMessage()]);
        }

        return back()->with('success', 'Run loaded and stock issued.');
    }

    public function closeRun(Request $request, DeliveryRun $run, DeliveryService $service): RedirectResponse
    {
        abort_unless($request->user()->worksAt($run->location_id), 403);
        $request->validate(['end_odometer' => ['required', 'integer', 'min:0']]);
        try {
            $service->closeRun($run, (int) $request->integer('end_odometer'), $request->user());
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['end_odometer' => $e->getMessage()]);
        }

        return back()->with('success', 'Run closed and vehicle odometer updated.');
    }

    public function reorderStops(Request $request, DeliveryRun $run): RedirectResponse
    {
        $data = $request->validate(['stop_ids' => ['required', 'array'], 'stop_ids.*' => ['integer', 'exists:delivery_stops,id']]);
        abort_unless($request->user()->worksAt($run->location_id) && $request->user()->role->canOverride(), 403);
        $allowed = $run->stops()->pluck('id')->sort()->values()->all();
        $ids = collect($data['stop_ids'])->sort()->values()->all();
        abort_unless($ids === $allowed, 422);
        foreach ($data['stop_ids'] as $i => $id) {
            $run->stops()->whereKey($id)->update(['sort_order' => $i + 1]);
        }

        return back()->with('success', 'Stop order saved.');
    }

    public function reschedule(Request $request, Delivery $delivery, DeliveryService $service): RedirectResponse
    {
        abort_unless($request->user()->worksAt($delivery->location_id), 403);
        $data = $request->validate(['scheduled_date' => ['required', 'date'], 'time_window' => ['nullable', 'string', 'max:80']]);
        try {
            $service->reschedule($delivery, $data['scheduled_date'], $data['time_window'] ?? null, $request->user());
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['scheduled_date' => $e->getMessage()]);
        }

        return back()->with('success', 'Delivery rescheduled.');
    }

    public function handOver(Request $request, DeliveryService $service): RedirectResponse
    {
        $data = $request->validate(['collection_ids' => ['required', 'array', 'min:1'], 'collection_ids.*' => ['integer', 'exists:driver_collections,id'], 'shift_id' => ['required', 'integer', 'exists:shifts,id'], 'counted_cash' => ['required', 'numeric', 'min:0', 'regex:/^\d+(?:\.\d{1,2})?$/'], 'approver_id' => ['nullable', 'integer'], 'pin' => ['nullable', 'string', 'max:6']]);
        try {
            $service->handOver($data['collection_ids'], $data['shift_id'], Money::fromDecimal((string) $data['counted_cash'])->cents, $request->user(), $data['approver_id'] ?? null, $data['pin'] ?? null);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['collection_ids' => $e->getMessage()]);
        }

        return back()->with('success', 'Driver collections handed over into the cashier shift.');
    }

    public function completeInstallation(Request $request, InstallationJob $job, DeliveryService $service): RedirectResponse
    {
        abort_unless($request->user()->worksAt($job->location_id) && $request->user()->role->canOverride(), 403);
        $data = $request->validate([
            'checklist' => ['nullable', 'array', 'max:30'],
            'checklist.*' => ['string', 'max:160'],
            'photos' => ['nullable', 'array', 'max:5'],
            'photos.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ]);
        $paths = collect($data['photos'] ?? [])->map(fn ($photo) => Storage::disk('local')->putFile('installation-proof', $photo))->all();
        $service->completeInstallation($job, ['checklist' => $data['checklist'] ?? [], 'photos' => $paths], $request->user());

        return back()->with('success', 'Installation completed and proof saved.');
    }

    private function present(Delivery $d): array
    {
        return ['id' => $d->id, 'number' => $d->number, 'status' => $d->status->value, 'scheduled_date' => $d->scheduled_date->toDateString(), 'time_window' => $d->time_window, 'customer' => $d->salesOrder?->customer?->name, 'order' => $d->salesOrder?->invoice_number ?: $d->salesOrder?->number, 'address' => $d->address, 'contact_phone' => $d->contact_phone, 'zone' => $d->zone?->name, 'balance_to_collect' => $d->balance_to_collect, 'requires_installation' => $d->requires_installation, 'failure_reason' => $d->failure_reason, 'receiver_name' => $d->receiver_name, 'lines' => $d->lines->map(fn ($l) => ['name' => $l->variant?->product?->name, 'quantity' => $l->quantity])->values()];
    }
}
