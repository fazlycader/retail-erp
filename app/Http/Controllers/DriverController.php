<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\Delivery;
use App\Models\DeliveryRun;
use App\Models\DriverCollection;
use App\Services\Delivery\DeliveryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class DriverController extends Controller
{
    public function index(Request $request): Response
    {
        $driver = $request->user();
        abort_unless($driver->role === Role::Driver, 403);
        $unlocked = (bool) $request->session()->get('driver_unlocked', false);
        $run = $unlocked ? DeliveryRun::query()->with(['vehicle:id,name,registration', 'stops' => fn ($q) => $q->orderBy('sort_order'), 'stops.delivery.salesOrder.customer', 'stops.delivery.lines.variant.product'])
            ->where('driver_id', $driver->id)->whereDate('run_date', today())->whereIn('status', ['planned', 'loaded', 'out_for_delivery'])->first() : null;
        $stops = $run?->stops->map(function ($stop) {
            $d = $stop->delivery;

            return ['id' => $d->id, 'number' => $d->number, 'status' => $d->status->value, 'customer' => $d->salesOrder?->customer?->name, 'address' => $d->address, 'phone' => $d->contact_phone, 'window' => $d->time_window, 'balance' => $d->balance_to_collect, 'failure_reason' => $d->failure_reason, 'items' => $d->lines->map(fn ($l) => ['name' => $l->variant?->product?->name, 'quantity' => $l->quantity])->values()];
        })->values() ?? collect();
        $collections = $unlocked ? DriverCollection::query()->where('driver_id', $driver->id)->where('status', 'held_by_driver')->with('delivery:id,number')->orderBy('id')->get()->map(fn ($c) => ['id' => $c->id, 'delivery' => $c->delivery?->number, 'amount' => $c->amount, 'method' => $c->method, 'reference' => $c->reference]) : collect();

        return Inertia::render('Driver/Index', [
            'unlocked' => $unlocked, 'hasPin' => $driver->hasPin(),
            'run' => $run ? ['id' => $run->id, 'number' => $run->number, 'status' => $run->status, 'vehicle' => $run->vehicle?->name.' · '.$run->vehicle?->registration, 'stops' => $stops] : null,
            'collections' => $collections,
        ]);
    }

    public function unlock(Request $request): RedirectResponse
    {
        $request->validate(['pin' => ['required', 'digits_between:4,6']]);
        $driver = $request->user();
        abort_unless($driver->role === Role::Driver, 403);
        if (! $driver->attemptPin($request->string('pin')->toString(), 'driver-app')) {
            return back()->withErrors(['pin' => $driver->pinLocked() ? 'PIN is locked. Ask a manager to unlock it.' : 'Incorrect PIN.']);
        }
        $request->session()->put('driver_unlocked', true);

        return back()->with('success', 'Driver route unlocked.');
    }

    public function startRun(Request $request, DeliveryRun $run, DeliveryService $service): RedirectResponse
    {
        abort_unless($request->session()->get('driver_unlocked') && $run->driver_id === $request->user()->id, 403);
        $service->startRun($run, $request->user());

        return back()->with('success', 'Route started.');
    }

    public function action(Request $request, DeliveryService $service): JsonResponse
    {
        abort_unless($request->session()->get('driver_unlocked') && $request->user()->role === Role::Driver, 403);
        $data = $request->validate([
            'action_id' => ['required', 'uuid'], 'delivery_id' => ['required', 'integer', 'exists:deliveries,id'],
            'action' => ['required', Rule::in(['arrived', 'delivered', 'failed', 'collect'])],
            'reason' => ['nullable', 'string', 'max:255'], 'receiver_name' => ['nullable', 'string', 'max:160'],
            'signature' => ['nullable', 'string', 'max:6000000'], 'photos' => ['nullable', 'array', 'max:5'], 'photos.*' => ['string', 'max:6000000'],
            'amount' => ['required_if:action,collect', 'nullable', 'integer', 'min:1'], 'method' => ['required_if:action,collect', 'nullable', Rule::in(['cash', 'bank_transfer'])], 'reference' => ['nullable', 'string', 'max:100'],
        ]);
        $delivery = Delivery::query()->findOrFail($data['delivery_id']);
        $proof = [];
        if (($data['action'] ?? null) === 'delivered') {
            if (empty($data['signature'])) {
                return response()->json(['message' => 'Capture the customer signature before delivery can be completed.'], 422);
            }
            $signaturePath = $this->storeDataImage($data['signature'], 'delivery-proof/signatures');
            $photoPaths = [];
            foreach ($data['photos'] ?? [] as $photo) {
                $photoPaths[] = $this->storeDataImage($photo, 'delivery-proof/photos');
            }
            $proof = ['receiver_name' => $data['receiver_name'] ?? '', 'signature_path' => $signaturePath, 'photos' => $photoPaths];
        }
        $payload = $data + $proof;
        try {
            return response()->json(['ok' => true, ...$service->action($delivery, $data['action_id'], $data['action'], $payload, $request->user())]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    private function storeDataImage(string $dataUrl, string $folder): string
    {
        if (! preg_match('#^data:image/(png|jpeg|webp);base64,#', $dataUrl, $m)) {
            abort(422, 'Proof images must be PNG, JPEG or WebP.');
        }
        $bytes = base64_decode(substr($dataUrl, strpos($dataUrl, ',') + 1), true);
        if ($bytes === false || strlen($bytes) > 4_000_000) {
            abort(422, 'Proof image is invalid or too large.');
        }
        $ext = $m[1] === 'jpeg' ? 'jpg' : $m[1];
        $path = $folder.'/'.Str::uuid().'.'.$ext;
        Storage::disk('local')->put($path, $bytes);

        return $path;
    }
}
