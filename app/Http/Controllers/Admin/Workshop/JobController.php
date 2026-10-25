<?php

namespace App\Http\Controllers\Admin\Workshop;

use App\Enums\JobPriority;
use App\Enums\ProductionSource;
use App\Enums\SupplierProgress;
use App\Exceptions\InsufficientStock;
use App\Http\Controllers\Controller;
use App\Models\JobLabour;
use App\Models\Location;
use App\Models\ProductionJob;
use App\Models\ProductionStage;
use App\Models\ProductVariant;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Production\JobPresenter;
use App\Services\Production\ProductionService;
use App\Support\ImageUploader;
use App\Support\Money;
use App\Support\Quantity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class JobController extends Controller
{
    public function show(Request $request, ProductionJob $job): Response
    {
        $job->loadMissing('salesOrder:id,number,invoice_number,customer_id,location_id,fulfilment_type');
        $this->authorizeJob($request, $job);

        $user = $request->user();
        $stages = $job->source === ProductionSource::Workshop
            ? ProductionStage::forWorkshop($job->location_id)->map(fn (ProductionStage $s) => ['id' => $s->id, 'name' => $s->name])->values()
            : [];

        $staff = User::query()
            ->where('is_active', true)
            ->with('locations:id')
            ->orderBy('name')
            ->get(['id', 'name', 'role'])
            ->filter(fn (User $u) => $u->worksAt($job->location_id))
            ->values()
            ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name]);

        $destinations = Location::query()
            ->where('is_active', true)
            ->where('id', '!=', $job->location_id)
            ->orderBy('id')
            ->get(['id', 'name', 'code', 'type']);

        return Inertia::render('Admin/Workshop/Show', [
            'job' => JobPresenter::detail($job, $user),
            'stages' => $stages,
            'priorities' => JobPriority::options(),
            'supplierStatuses' => collect(SupplierProgress::cases())
                ->reject(fn (SupplierProgress $s) => $s === SupplierProgress::Received)
                ->map(fn (SupplierProgress $s) => ['value' => $s->value, 'label' => $s->label()])
                ->values(),
            'staff' => $staff,
            'destinations' => $destinations,
            'defaultDestinationId' => $job->salesOrder?->location_id && $job->salesOrder->location_id !== $job->location_id
                ? $job->salesOrder->location_id
                : $destinations->first()?->id,
            'suppliers' => $user->can('manage-workshop')
                ? Supplier::query()->active()->orderBy('name')->get(['id', 'name'])
                : [],
            'canManage' => $user->can('manage-workshop'),
            'canViewCosts' => $user->can('view-job-costs'),
        ]);
    }

    public function update(Request $request, ProductionJob $job, ProductionService $production): RedirectResponse
    {
        Gate::authorize('manage-workshop');
        $this->authorizeJob($request, $job);

        $data = $request->validate([
            'priority' => ['required', Rule::enum(JobPriority::class)],
            'due_date' => ['nullable', 'date'],
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $production->update($job, $data, $request->user());

        return back()->with('success', "Job {$job->number} updated.");
    }

    public function moveStage(Request $request, ProductionJob $job, ProductionService $production, ImageUploader $images): RedirectResponse
    {
        $this->authorizeJob($request, $job);

        $data = $request->validate([
            'stage_id' => ['nullable', 'integer'],
            'note' => ['nullable', 'string', 'max:500'],
            'photos' => ['nullable', 'array', 'max:6'],
            'photos.*' => ImageUploader::rules(),
        ], ImageUploader::messages('photos.*'));

        $photos = [];
        foreach ($request->file('photos', []) as $file) {
            $stored = $images->store($file, 'job-stages', 'local');
            $photos[] = ['path' => $stored['image_path'], 'thumb' => $stored['thumbnail_path']];
        }

        try {
            $production->moveToStage($job, isset($data['stage_id']) ? (int) $data['stage_id'] : null, $request->user(), $data['note'] ?? null, $photos);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['stage_id' => $e->getMessage()]);
        }

        return back()->with('success', 'Stage updated.');
    }

    public function addNote(Request $request, ProductionJob $job, ProductionService $production, ImageUploader $images): RedirectResponse
    {
        $this->authorizeJob($request, $job);

        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:1000'],
            'photos' => ['nullable', 'array', 'max:6'],
            'photos.*' => ImageUploader::rules(),
        ], ImageUploader::messages('photos.*'));

        $photos = [];
        foreach ($request->file('photos', []) as $file) {
            $stored = $images->store($file, 'job-stages', 'local');
            $photos[] = ['path' => $stored['image_path'], 'thumb' => $stored['thumbnail_path']];
        }

        try {
            $production->addNote($job, $request->user(), $data['note'] ?? null, $photos);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['note' => $e->getMessage()]);
        }

        return back()->with('success', 'Logged on the job.');
    }

    public function issueMaterial(Request $request, ProductionJob $job, ProductionService $production): RedirectResponse
    {
        $this->authorizeJob($request, $job);

        $data = $request->validate([
            'product_variant_id' => ['nullable', 'integer', 'exists:product_variants,id'],
            'code' => ['nullable', 'string', 'max:80'],
            'quantity' => ['required', 'string', 'max:20'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $variant = $this->resolveMaterialVariant($data['product_variant_id'] ?? null, $data['code'] ?? null);

        try {
            $qty = Quantity::parse($data['quantity']);
            $production->issueMaterial($job, $variant, $qty, $request->user(), $data['note'] ?? null);
        } catch (InsufficientStock|InvalidArgumentException $e) {
            throw ValidationException::withMessages(['material' => $e->getMessage()]);
        }

        return back()->with('success', "Issued {$variant->sku} to {$job->number}.");
    }

    public function returnMaterial(Request $request, ProductionJob $job, ProductionService $production): RedirectResponse
    {
        $this->authorizeJob($request, $job);

        $data = $request->validate([
            'product_variant_id' => ['nullable', 'integer', 'exists:product_variants,id'],
            'code' => ['nullable', 'string', 'max:80'],
            'quantity' => ['required', 'string', 'max:20'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $variant = $this->resolveMaterialVariant($data['product_variant_id'] ?? null, $data['code'] ?? null);

        try {
            $qty = Quantity::parse($data['quantity']);
            $production->returnMaterial($job, $variant, $qty, $request->user(), $data['note'] ?? null);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['material' => $e->getMessage()]);
        }

        return back()->with('success', "Returned {$variant->sku} from {$job->number}.");
    }

    public function addLabour(Request $request, ProductionJob $job, ProductionService $production): RedirectResponse
    {
        Gate::authorize('manage-workshop');
        $this->authorizeJob($request, $job);

        $data = $request->validate([
            'worker_name' => ['required', 'string', 'max:120'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'work_date' => ['required', 'date'],
            'hours' => ['nullable', 'numeric', 'min:0.01', 'max:240'],
            'hourly_rate' => ['nullable', 'string', 'max:20'],
            'amount' => ['nullable', 'string', 'max:20'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $minutes = isset($data['hours']) && $data['hours'] !== null && $data['hours'] !== ''
                ? (int) round((float) $data['hours'] * 60)
                : null;
            $rate = isset($data['hourly_rate']) && trim((string) $data['hourly_rate']) !== ''
                ? Money::fromDecimal((string) $data['hourly_rate'])->cents
                : null;
            $amount = isset($data['amount']) && trim((string) $data['amount']) !== ''
                ? Money::fromDecimal((string) $data['amount'])->cents
                : null;

            $production->addLabour($job, [
                'worker_name' => $data['worker_name'],
                'user_id' => $data['user_id'] ?? null,
                'work_date' => $data['work_date'],
                'minutes' => $minutes,
                'hourly_rate' => $rate,
                'amount' => $amount,
                'note' => $data['note'] ?? null,
            ], $request->user());
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['labour' => $e->getMessage()]);
        }

        return back()->with('success', 'Labour logged.');
    }

    public function removeLabour(Request $request, ProductionJob $job, JobLabour $labour, ProductionService $production): RedirectResponse
    {
        Gate::authorize('manage-workshop');
        $this->authorizeJob($request, $job);
        abort_unless($labour->production_job_id === $job->id, 404);

        try {
            $production->removeLabour($labour, $request->user());
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['labour' => $e->getMessage()]);
        }

        return back()->with('success', 'Labour entry removed.');
    }

    public function complete(Request $request, ProductionJob $job, ProductionService $production): RedirectResponse
    {
        $this->authorizeJob($request, $job);

        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $production->complete($job, $request->user(), $data['note'] ?? null);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['status' => $e->getMessage()]);
        }

        return back()->with('success', "Job {$job->number} marked ready: finished piece is in stock and reserved for the order.");
    }

    public function sendToLocation(Request $request, ProductionJob $job, ProductionService $production): RedirectResponse
    {
        $this->authorizeJob($request, $job);

        $data = $request->validate([
            'to_location_id' => ['required', 'integer', 'exists:locations,id'],
        ]);

        try {
            $transfer = $production->sendToLocation($job, (int) $data['to_location_id'], $request->user());
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['to_location_id' => $e->getMessage()]);
        }

        return back()->with('success', "Transfer {$transfer->number} created to move the finished piece.");
    }

    public function outsource(Request $request, ProductionJob $job, ProductionService $production): RedirectResponse
    {
        Gate::authorize('manage-workshop');
        $this->authorizeJob($request, $job);

        $data = $request->validate([
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'unit_cost' => ['required', 'string', 'max:20'],
            'deliver_to_location_id' => ['required', 'integer', 'exists:locations,id'],
        ]);

        try {
            $costCents = Money::fromDecimal($data['unit_cost'])->cents;
            $production->outsource($job, (int) $data['supplier_id'], $costCents, (int) $data['deliver_to_location_id'], $request->user());
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['outsource' => $e->getMessage()]);
        }

        return back()->with('success', "Job {$job->number} outsourced to supplier (draft PO created).");
    }

    private function resolveMaterialVariant(?int $variantId, ?string $code): ProductVariant
    {
        $query = ProductVariant::query()->with('product.category.parent.parent');

        if ($variantId) {
            $variant = $query->find($variantId);
        } elseif ($code && trim($code) !== '') {
            $clean = trim($code);
            $variant = $query->where(fn ($q) => $q->where('sku', $clean)->orWhere('barcode', $clean))->first();
        } else {
            $variant = null;
        }

        if (! $variant) {
            throw ValidationException::withMessages(['material' => 'Material SKU or barcode not found.']);
        }

        return $variant;
    }

    private function authorizeJob(Request $request, ProductionJob $job): void
    {
        $user = $request->user();
        $allowed = $user->can('work-on-jobs') && (
            $user->worksAt($job->location_id)
            || ($job->salesOrder && $user->worksAt($job->salesOrder->location_id))
        );

        abort_unless($allowed, 403);
    }
}

