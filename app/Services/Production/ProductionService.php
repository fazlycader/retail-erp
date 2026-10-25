<?php

namespace App\Services\Production;

use App\Enums\DocumentType;
use App\Enums\LocationType;
use App\Enums\MovementType;
use App\Enums\ProductionJobStatus;
use App\Enums\ProductionSource;
use App\Enums\ProductType;
use App\Enums\ReservationStatus;
use App\Enums\SalesLineSource;
use App\Enums\SalesOrderStatus;
use App\Enums\StockUnitCondition;
use App\Enums\StockUnitStatus;
use App\Enums\SupplierProgress;
use App\Models\AuditLog;
use App\Models\CompanySetting;
use App\Models\GoodsReceipt;
use App\Models\JobLabour;
use App\Models\JobMaterial;
use App\Models\JobStageLog;
use App\Models\Location;
use App\Models\ProductionJob;
use App\Models\ProductionStage;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\StockUnit;
use App\Models\Transfer;
use App\Models\User;
use App\Services\Inventory\ReservationService;
use App\Services\Inventory\StockService;
use App\Services\Inventory\TransferService;
use App\Services\Purchasing\PurchaseOrderService;
use App\Services\Sms\SmsService;
use App\Support\Counter;
use App\Support\Quantity;
use App\Support\SyncVersion;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Custom orders from the customer's idea to the finished piece: one job per custom line, made in our
 * workshop (moving through its stages; materials issued and returned by hand; labour logged) or at
 * a supplier (back-to-back PO, supplier progress). Completing a workshop job puts the piece in stock
 * as a stock unit (production_in) at the workshop, reserved for the order and valued at the job
 * cost; a supplier job becomes ready when its goods receipt arrives. Customers get an SMS when
 * their order is confirmed, goes into production and is ready.
 */
class ProductionService
{
    public function __construct(
        private readonly StockService $stock,
        private readonly ReservationService $reservations,
        private readonly TransferService $transfers,
        private readonly PurchaseOrderService $purchaseOrders,
        private readonly SmsService $sms,
    ) {}

    /** A custom piece, or a made-to-order product: made (or bought) for this order. */
    public static function needsJob(SalesOrderLine $line): bool
    {
        if ($line->is_service) {
            return false;
        }

        return $line->source === SalesLineSource::Make
            || ($line->source === SalesLineSource::SupplierOrder && ($line->is_custom || $line->variant?->product?->type === ProductType::MadeToOrder));
    }

    /**
     * On confirmation (inside SalesOrderService::confirm, before the back-to-back POs): one job per
     * custom line. Custom supplier lines get the custom-piece variant so their PO can be drafted.
     */
    public function createForOrder(SalesOrder $order, User $actor): void
    {
        $order->loadMissing(['lines.variant.product', 'lines.customSpec', 'customer', 'location']);

        foreach ($order->lines as $line) {
            if (self::needsJob($line) && ! ProductionJob::query()->where('sales_order_line_id', $line->id)->exists()) {
                $this->createForLine($order, $line, $actor);
            }
        }
    }

    /** After the back-to-back POs exist: link supplier jobs to their PO line. */
    public function linkPurchaseOrders(SalesOrder $order): void
    {
        foreach (ProductionJob::query()->where('sales_order_id', $order->id)->where('source', ProductionSource::Supplier->value)->whereNull('purchase_order_line_id')->get() as $job) {
            $poLineId = SalesOrderLine::query()->whereKey($job->sales_order_line_id)->value('purchase_order_line_id');
            if ($poLineId) {
                $job->update(['purchase_order_line_id' => $poLineId, 'supplier_status' => SupplierProgress::Ordered, 'supplier_status_at' => now()]);
            }
        }
    }

    public function createForLine(SalesOrder $order, SalesOrderLine $line, User $actor): ProductionJob
    {
        $settings = CompanySetting::current();
        $source = $line->source === SalesLineSource::SupplierOrder ? ProductionSource::Supplier : ProductionSource::Workshop;
        $locationId = $source === ProductionSource::Workshop ? self::workshopFor($line) : null;
        if ($locationId === null) {
            $source = ProductionSource::Supplier;
            $locationId = (int) ($line->fulfil_location_id ?: $order->location_id);
        }

        if ($source === ProductionSource::Supplier && ! $line->product_variant_id) {
            $line->update(['product_variant_id' => CustomPiece::variant()->id, 'source' => SalesLineSource::SupplierOrder]);
        }

        $lead = $line->customSpec?->lead_time_days ?? $settings->workshop_lead_days;
        $job = ProductionJob::query()->create([
            'number' => $this->nextNumber($locationId),
            'location_id' => $locationId,
            'sales_order_id' => $order->id,
            'sales_order_line_id' => $line->id,
            'custom_spec_id' => $line->custom_spec_id,
            'product_variant_id' => $line->product_variant_id,
            'title' => $line->name_snapshot,
            'quantity' => max(1000, $line->quantity),
            'source' => $source,
            'status' => ProductionJobStatus::Pending,
            'due_date' => $order->requested_delivery_date ?? CarbonImmutable::parse($order->confirmed_at ?? now())->addDays($lead)->toDateString(),
            'overhead_bp' => $settings->workshop_overhead_bp,
            'created_by' => $actor->id,
        ]);
        $this->log($job, 'created', $actor, "Created from {$order->number}");

        $this->sms->queue('custom_confirmed', $order->customer, $this->smsVars($job, $order), $job, "job:{$job->id}:confirmed", $order->location_id);

        return $job;
    }

    /** The workshop a line is made at: its fulfil location if that is a workshop, else the default one. */
    public static function workshopFor(SalesOrderLine $line): ?int
    {
        $workshops = Location::query()->where('type', LocationType::Workshop->value)->where('is_active', true)->orderBy('id')->pluck('id');
        if ($workshops->contains($line->fulfil_location_id)) {
            return (int) $line->fulfil_location_id;
        }
        $default = CompanySetting::current()->workshop_location_id;

        return $default && $workshops->contains($default) ? (int) $default : ($workshops->first() ? (int) $workshops->first() : null);
    }

    public function nextNumber(int $locationId): string
    {
        $code = Location::query()->whereKey($locationId)->value('code');

        return sprintf('%s-%s-%06d', CompanySetting::current()->prefixFor(DocumentType::ProductionJob), $code, Counter::next(DocumentType::ProductionJob->value, "location:{$locationId}"));
    }

    // ---- Workshop: stages, notes, photos -------------------------------------------------------

    /** Drag on the board. Moving into the first stage starts production (and texts the customer). */
    public function moveToStage(ProductionJob $job, ?int $stageId, User $actor, ?string $note = null, array $photos = []): ProductionJob
    {
        return DB::transaction(function () use ($job, $stageId, $actor, $note, $photos) {
            $job = ProductionJob::query()->whereKey($job->id)->lockForUpdate()->firstOrFail();
            $this->assertWorkshopOpen($job);

            if ($stageId !== null && ! ProductionStage::query()->whereKey($stageId)->where('location_id', $job->location_id)->where('is_active', true)->exists()) {
                throw new InvalidArgumentException('That stage does not belong to this workshop.');
            }
            if ($stageId === $job->production_stage_id) {
                return $job;
            }

            $from = $job->production_stage_id;
            $job->production_stage_id = $stageId;
            $starting = $stageId !== null && $job->status === ProductionJobStatus::Pending;
            if ($starting) {
                $job->status = ProductionJobStatus::InProduction;
                $job->started_at = now();
            }
            $job->save();

            JobStageLog::query()->create(['production_job_id' => $job->id, 'from_stage_id' => $from, 'to_stage_id' => $stageId, 'event' => 'moved', 'user_id' => $actor->id, 'note' => $note ?: null, 'photos' => $photos ?: null, 'created_at' => now()]);

            if ($starting) {
                $this->startedProduction($job, $actor);
            }

            return $job;
        });
    }

    /** A note and/or photos without moving the job. */
    public function addNote(ProductionJob $job, User $actor, ?string $note, array $photos = []): JobStageLog
    {
        if (blank($note) && $photos === []) {
            throw new InvalidArgumentException('Write a note or add a photo.');
        }

        return DB::transaction(function () use ($job, $actor, $note, $photos) {
            $log = JobStageLog::query()->create(['production_job_id' => $job->id, 'from_stage_id' => $job->production_stage_id, 'to_stage_id' => $job->production_stage_id, 'event' => $photos ? 'photo' : 'note', 'user_id' => $actor->id, 'note' => $note ?: null, 'photos' => $photos ?: null, 'created_at' => now()]);
            $job->touch(); // the board picks it up

            return $log;
        });
    }

    public function update(ProductionJob $job, array $data, User $actor): ProductionJob
    {
        return DB::transaction(function () use ($job, $data, $actor) {
            $before = $job->only(['priority', 'due_date', 'assigned_to', 'notes']);
            $job->fill(array_intersect_key($data, array_flip(['priority', 'due_date', 'assigned_to', 'notes'])))->save();
            AuditLog::record('production_job.updated', $job, "Job {$job->number} updated", $before, $job->only(array_keys($before)), $actor);

            return $job;
        });
    }

    // ---- Materials and labour -------------------------------------------------------------------

    /** Issue a raw material (milli-units) from the workshop's stock to the job, at its average cost. */
    public function issueMaterial(ProductionJob $job, ProductVariant $variant, int $quantity, User $actor, ?string $note = null): JobMaterial
    {
        if ($quantity <= 0) {
            throw new InvalidArgumentException('Enter a quantity to issue.');
        }
        if (! $variant->product?->isMaterial()) {
            throw new InvalidArgumentException("[{$variant->sku}] is not a raw material (its category is not marked as materials).");
        }

        return DB::transaction(function () use ($job, $variant, $quantity, $actor, $note) {
            $job = ProductionJob::query()->whereKey($job->id)->lockForUpdate()->firstOrFail();
            $this->assertWorkshopOpen($job);

            $unitCost = (int) ($variant->average_cost ?: $variant->last_cost ?: 0);
            $movement = $this->stock->move([
                'product_variant_id' => $variant->id, 'location_id' => $job->location_id, 'type' => MovementType::MaterialIssue,
                'quantity' => -$quantity, 'unit_cost' => $unitCost, 'reference' => $job, 'user_id' => $actor->id, 'note' => $note ?: "Issued to {$job->number}",
            ]);

            $material = JobMaterial::query()->create([
                'production_job_id' => $job->id, 'product_variant_id' => $variant->id, 'location_id' => $job->location_id, 'type' => JobMaterial::ISSUE,
                'quantity' => $quantity, 'unit_cost' => $unitCost, 'cost' => Quantity::times($quantity, $unitCost),
                'stock_movement_id' => $movement->id, 'user_id' => $actor->id, 'note' => $note ?: null, 'created_at' => now(),
            ]);
            $this->recalculate($job);

            return $material;
        });
    }

    /** Return unused material to stock, valued at what it cost this job (never more than issued). */
    public function returnMaterial(ProductionJob $job, ProductVariant $variant, int $quantity, User $actor, ?string $note = null): JobMaterial
    {
        if ($quantity <= 0) {
            throw new InvalidArgumentException('Enter a quantity to return.');
        }

        return DB::transaction(function () use ($job, $variant, $quantity, $actor, $note) {
            $job = ProductionJob::query()->whereKey($job->id)->lockForUpdate()->firstOrFail();
            if ($job->source !== ProductionSource::Workshop || $job->status === ProductionJobStatus::Ready) {
                throw new InvalidArgumentException("Job {$job->number} is {$job->status->label()}: materials can no longer be returned to it.");
            }

            $rows = JobMaterial::query()->where('production_job_id', $job->id)->where('product_variant_id', $variant->id)->get();
            $netQty = (int) $rows->sum(fn (JobMaterial $m) => $m->signedQuantity());
            $netCost = (int) $rows->sum(fn (JobMaterial $m) => $m->signedCost());
            if ($quantity > $netQty) {
                throw new InvalidArgumentException('Only '.Quantity::format($netQty, $variant->product?->unit ?? 'pcs')." of [{$variant->sku}] is issued to this job.");
            }

            $cost = JobCost::returnCost($netCost, $netQty, $quantity);
            $unitCost = intdiv($cost * Quantity::SCALE * 2 + $quantity, $quantity * 2);
            $movement = $this->stock->move([
                'product_variant_id' => $variant->id, 'location_id' => $job->location_id, 'type' => MovementType::MaterialReturn,
                'quantity' => $quantity, 'unit_cost' => $unitCost, 'reference' => $job, 'user_id' => $actor->id, 'note' => $note ?: "Returned from {$job->number}",
            ]);

            $material = JobMaterial::query()->create([
                'production_job_id' => $job->id, 'product_variant_id' => $variant->id, 'location_id' => $job->location_id, 'type' => JobMaterial::RETURN,
                'quantity' => $quantity, 'unit_cost' => $unitCost, 'cost' => $cost,
                'stock_movement_id' => $movement->id, 'user_id' => $actor->id, 'note' => $note ?: null, 'created_at' => now(),
            ]);
            $this->recalculate($job);

            return $material;
        });
    }

    /**
     * @param  array{worker_name: string, user_id?: int|null, work_date: string, minutes?: int|null, hourly_rate?: int|null, amount?: int|null, note?: string|null}  $data
     */
    public function addLabour(ProductionJob $job, array $data, User $actor): JobLabour
    {
        return DB::transaction(function () use ($job, $data, $actor) {
            $job = ProductionJob::query()->whereKey($job->id)->lockForUpdate()->firstOrFail();
            $this->assertWorkshopOpen($job);

            $minutes = isset($data['minutes']) ? (int) $data['minutes'] : null;
            $rate = isset($data['hourly_rate']) ? (int) $data['hourly_rate'] : null;
            $amount = $minutes !== null && $rate !== null ? JobCost::labour($minutes, $rate) : (int) ($data['amount'] ?? 0);
            if ($amount <= 0) {
                throw new InvalidArgumentException('Enter hours and an hourly rate, or a fixed amount.');
            }

            $labour = JobLabour::query()->create([
                'production_job_id' => $job->id, 'user_id' => $data['user_id'] ?? null, 'worker_name' => trim($data['worker_name']),
                'work_date' => $data['work_date'], 'minutes' => $minutes, 'hourly_rate' => $minutes !== null ? $rate : null,
                'amount' => $amount, 'note' => $data['note'] ?? null, 'created_by' => $actor->id,
            ]);
            $this->recalculate($job);

            return $labour;
        });
    }

    public function removeLabour(JobLabour $labour, User $actor): void
    {
        DB::transaction(function () use ($labour, $actor) {
            $job = ProductionJob::query()->whereKey($labour->production_job_id)->lockForUpdate()->firstOrFail();
            $this->assertWorkshopOpen($job);
            AuditLog::record('production_job.labour_removed', $job, "Labour removed from {$job->number}", $labour->only(['worker_name', 'work_date', 'amount']), null, $actor);
            $labour->delete();
            $this->recalculate($job);
        });
    }

    /** Materials + labour + overhead (the overhead % follows the setting until the job is finished). */
    public function recalculate(ProductionJob $job): ProductionJob
    {
        if ($job->status->isOpen()) {
            $job->overhead_bp = CompanySetting::current()->workshop_overhead_bp;
        }

        $cost = JobCost::total(
            JobMaterial::query()->where('production_job_id', $job->id)->get()->map(fn (JobMaterial $m) => $m->signedCost())->all(),
            JobLabour::query()->where('production_job_id', $job->id)->pluck('amount')->map(fn ($a) => (int) $a)->all(),
            $job->overhead_bp,
        );
        $job->fill($cost)->save();

        return $job;
    }

    // ---- Finishing --------------------------------------------------------------------------------

    /**
     * The piece is finished: each piece becomes a stock unit (production_in) at the workshop, valued
     * at its share of the job cost and reserved for the customer's order line.
     */
    public function complete(ProductionJob $job, User $actor, ?string $note = null): ProductionJob
    {
        return DB::transaction(function () use ($job, $actor, $note) {
            $job = ProductionJob::query()->with(['salesOrderLine.salesOrder.customer'])->whereKey($job->id)->lockForUpdate()->firstOrFail();
            $this->assertWorkshopOpen($job);

            $this->recalculate($job);
            $variant = $job->product_variant_id ? ProductVariant::withTrashed()->with('product')->findOrFail($job->product_variant_id) : CustomPiece::variant();
            $line = $job->salesOrderLine;
            $order = $line?->salesOrder;
            $forOrder = $line && $order && $order->status !== SalesOrderStatus::Cancelled;
            $costs = JobCost::split($job->total_cost, $job->pieces());

            $units = [];
            foreach ($costs as $i => $pieceCost) {
                $unit = StockUnit::query()->create([
                    'serial_number' => $job->pieces() > 1 ? "{$job->number}-".($i + 1) : $job->number,
                    'product_variant_id' => $variant->id,
                    'location_id' => $job->location_id,
                    'status' => StockUnitStatus::InStock,
                    'condition' => StockUnitCondition::New,
                    'unit_cost' => $pieceCost,
                    'received_at' => now(),
                    'note' => "Made on {$job->number}",
                ]);
                $this->stock->move([
                    'product_variant_id' => $variant->id, 'location_id' => $job->location_id, 'type' => MovementType::ProductionIn,
                    'quantity' => Quantity::SCALE, 'unit_cost' => $pieceCost, 'reference' => $job, 'stock_unit_id' => $unit->id,
                    'user_id' => $actor->id, 'note' => "Finished {$job->number}",
                ]);
                if ($forOrder) {
                    $this->reservations->reserve($variant, $job->location_id, Quantity::SCALE, $line, $unit->id, "Made for {$order->number} ({$job->number})", $actor->id);
                }
                $units[] = $unit;
            }

            if ($line) {
                $line->forceFill([
                    'product_variant_id' => $line->product_variant_id ?: $variant->id,
                    'stock_unit_id' => count($units) === 1 ? $units[0]->id : $line->stock_unit_id,
                    'unit_cost_snapshot' => intdiv($job->total_cost * 2 + $job->pieces(), $job->pieces() * 2),
                    'fulfil_location_id' => $job->location_id,
                    'reserved_qty' => $forOrder ? $line->reserved_qty + count($units) * Quantity::SCALE : $line->reserved_qty,
                ])->save();
            }

            $job->fill(['product_variant_id' => $variant->id, 'status' => ProductionJobStatus::Ready, 'completed_at' => now(), 'completed_by' => $actor->id])->save();
            $this->log($job, 'completed', $actor, $note ?: 'Finished: '.count($units).' piece(s) in stock at the workshop');
            AuditLog::record('production_job.completed', $job, "Job {$job->number} finished", null, ['total_cost' => $job->total_cost, 'units' => array_map(fn ($u) => $u->serial_number, $units)], $actor);

            if ($forOrder) {
                $this->ready($job, $order, $actor);
            }

            return $job;
        });
    }

    /**
     * Send a finished piece from the workshop to a showroom (a requested transfer; it stays
     * reserved for the order on the way, see TransferService).
     */
    public function sendToLocation(ProductionJob $job, int $toLocationId, User $actor): Transfer
    {
        if ($job->status !== ProductionJobStatus::Ready || ! $job->sales_order_line_id) {
            throw new InvalidArgumentException("Job {$job->number} has no finished piece waiting for an order.");
        }

        $units = StockUnit::query()->where('location_id', $job->location_id)->where('status', StockUnitStatus::Reserved->value)
            ->whereIn('id', DB::table('stock_reservations')->where('reserved_for_type', 'sales_order_line')->where('reserved_for_id', $job->sales_order_line_id)->where('status', ReservationStatus::Active->value)->pluck('stock_unit_id'))
            ->get();
        if ($units->isEmpty()) {
            throw new InvalidArgumentException('The finished piece is no longer at the workshop.');
        }

        return $this->transfers->create([
            'from_location_id' => $job->location_id,
            'to_location_id' => $toLocationId,
            'note' => "Finished {$job->number} for {$job->salesOrder?->number}",
            'lines' => $units->map(fn (StockUnit $u) => ['product_variant_id' => $u->product_variant_id, 'requested_qty' => Quantity::SCALE, 'stock_unit_id' => $u->id, 'sales_order_line_id' => $job->sales_order_line_id])->all(),
        ], $actor);
    }

    // ---- Outsourced jobs ----------------------------------------------------------------------------

    /** Have a supplier make it instead: a back-to-back PO line, and supplier progress from here on. */
    public function outsource(ProductionJob $job, int $supplierId, int $unitCost, int $deliverToLocationId, User $actor): ProductionJob
    {
        return DB::transaction(function () use ($job, $supplierId, $unitCost, $deliverToLocationId, $actor) {
            $job = ProductionJob::query()->with('salesOrderLine.salesOrder')->whereKey($job->id)->lockForUpdate()->firstOrFail();
            if ($job->source !== ProductionSource::Workshop || $job->status !== ProductionJobStatus::Pending) {
                throw new InvalidArgumentException("Only a workshop job that has not started can be outsourced ({$job->number} is {$job->status->label()}).");
            }
            if (JobMaterial::query()->where('production_job_id', $job->id)->exists() || JobLabour::query()->where('production_job_id', $job->id)->exists()) {
                throw new InvalidArgumentException('Return the materials and remove the labour on this job first.');
            }

            $variantId = $job->product_variant_id ?: CustomPiece::variant()->id;
            $line = $job->salesOrderLine;
            $po = $this->purchaseOrders->save([
                'supplier_id' => $supplierId,
                'location_id' => $deliverToLocationId,
                'expected_date' => $job->due_date?->toDateString(),
                'notes' => "Custom piece for {$line?->salesOrder?->number} ({$job->number})",
                'lines' => [['product_variant_id' => $variantId, 'ordered_qty' => $job->quantity, 'unit_cost' => $unitCost, 'sales_order_line_id' => $line?->id, 'note' => $job->title]],
            ], $actor);
            $poLine = $po->lines->first();

            $line?->forceFill(['product_variant_id' => $line->product_variant_id ?: $variantId, 'source' => SalesLineSource::SupplierOrder, 'purchase_order_line_id' => $poLine->id, 'fulfil_location_id' => $deliverToLocationId])->save();
            $job->fill([
                'source' => ProductionSource::Supplier, 'location_id' => $deliverToLocationId, 'product_variant_id' => $variantId, 'production_stage_id' => null,
                'purchase_order_line_id' => $poLine->id, 'supplier_status' => SupplierProgress::Ordered, 'supplier_status_at' => now(),
            ])->save();
            $this->log($job, 'outsourced', $actor, "Outsourced on {$po->number}");

            return $job;
        });
    }

    public function setSupplierStatus(ProductionJob $job, SupplierProgress $status, User $actor, ?string $note = null): ProductionJob
    {
        if ($status === SupplierProgress::Received) {
            throw new InvalidArgumentException('Received is set by the goods receipt (Purchasing → receive the PO).');
        }

        return DB::transaction(function () use ($job, $status, $actor, $note) {
            $job = ProductionJob::query()->whereKey($job->id)->lockForUpdate()->firstOrFail();
            if ($job->source !== ProductionSource::Supplier || ! $job->status->isOpen()) {
                throw new InvalidArgumentException("Job {$job->number} is not waiting for a supplier.");
            }

            $starting = $status !== SupplierProgress::Ordered && $job->status === ProductionJobStatus::Pending;
            $job->fill(['supplier_status' => $status, 'supplier_status_at' => now()] + ($starting ? ['status' => ProductionJobStatus::InProduction, 'started_at' => now()] : []))->save();
            $this->log($job, 'supplier', $actor, trim("Supplier: {$status->label()}. ".($note ?? '')));
            if ($starting) {
                $this->startedProduction($job, $actor);
            }

            return $job;
        });
    }

    /** Called by GoodsReceiptService: received pieces for an outsourced job are reserved for its order. */
    public function goodsReceived(GoodsReceipt $grn, User $actor): void
    {
        $poLineIds = $grn->lines()->whereNotNull('purchase_order_line_id')->pluck('received_qty', 'purchase_order_line_id');
        if ($poLineIds->isEmpty()) {
            return;
        }

        $jobs = ProductionJob::query()->with(['salesOrderLine.salesOrder.customer', 'purchaseOrderLine'])
            ->whereIn('purchase_order_line_id', $poLineIds->keys())->where('source', ProductionSource::Supplier->value)->open()->get();

        foreach ($jobs as $job) {
            $line = $job->salesOrderLine;
            $order = $line?->salesOrder;
            $received = (int) $poLineIds[$job->purchase_order_line_id];
            $toReserve = $line ? min($received, max(0, $job->quantity - $line->reserved_qty - $line->fulfilled_qty)) : 0;

            if ($toReserve > 0 && $order && $order->status !== SalesOrderStatus::Cancelled) {
                $this->reservations->reserve($line->product_variant_id ?: $job->product_variant_id, $grn->location_id, $toReserve, $line, null, "Received for {$order->number} ({$job->number})", $actor->id);
                $line->forceFill(['reserved_qty' => $line->reserved_qty + $toReserve, 'fulfil_location_id' => $grn->location_id])->save();
            }

            if ((int) $job->purchaseOrderLine?->received_qty >= $job->quantity) {
                $unitCost = (int) $job->purchaseOrderLine->unit_cost;
                $total = Quantity::times($job->quantity, $unitCost);
                $line?->forceFill(['unit_cost_snapshot' => $unitCost])->save();
                $job->fill([
                    'status' => ProductionJobStatus::Ready, 'supplier_status' => SupplierProgress::Received, 'supplier_status_at' => now(),
                    'location_id' => $grn->location_id, 'materials_cost' => $total, 'labour_cost' => 0, 'overhead_bp' => 0, 'overhead_cost' => 0, 'total_cost' => $total,
                    'completed_at' => now(), 'completed_by' => $actor->id,
                ])->save();
                $this->log($job, 'supplier', $actor, "Received on {$grn->number}");
                if ($order && $order->status !== SalesOrderStatus::Cancelled) {
                    $this->ready($job, $order, $actor);
                }
            }
        }
    }

    // ---- Order changes ----------------------------------------------------------------------------

    /** The order (or the line) was cancelled: open jobs stop. Issued materials stay on the job until returned. */
    public function cancelForLines(array $lineIds, User $actor, string $reason): void
    {
        foreach (ProductionJob::query()->whereIn('sales_order_line_id', $lineIds)->open()->get() as $job) {
            $job->fill(['status' => ProductionJobStatus::Cancelled, 'cancelled_at' => now()])->save();
            $this->log($job, 'cancelled', $actor, $reason);
        }
    }

    // ---- Helpers ------------------------------------------------------------------------------------

    private function assertWorkshopOpen(ProductionJob $job): void
    {
        if ($job->source !== ProductionSource::Workshop) {
            throw new InvalidArgumentException("Job {$job->number} is made by a supplier: follow it on the supplier progress list.");
        }
        if (! $job->status->isOpen()) {
            throw new InvalidArgumentException("Job {$job->number} is {$job->status->label()}.");
        }
    }

    private function startedProduction(ProductionJob $job, User $actor): void
    {
        $order = $job->salesOrder()->with('customer')->first();
        if (! $order) {
            return;
        }

        if ($order->status === SalesOrderStatus::Confirmed) {
            $this->setOrderStatus($order, SalesOrderStatus::InProduction, $actor, "{$job->number} in production");
        }
        $this->sms->queue('custom_in_production', $order->customer, $this->smsVars($job, $order), $job, "job:{$job->id}:in_production", $order->location_id);
    }

    private function ready(ProductionJob $job, SalesOrder $order, User $actor): void
    {
        $open = ProductionJob::query()->where('sales_order_id', $order->id)->where('id', '!=', $job->id)->open()->exists();
        if (! $open && in_array($order->status, [SalesOrderStatus::Confirmed, SalesOrderStatus::InProduction], true)) {
            $this->setOrderStatus($order, SalesOrderStatus::Ready, $actor, "{$job->number} ready");
        }
        $this->sms->queue('custom_ready', $order->customer, $this->smsVars($job, $order), $job, "job:{$job->id}:ready", $order->location_id);
    }

    private function setOrderStatus(SalesOrder $order, SalesOrderStatus $to, User $actor, string $note): void
    {
        $from = $order->status;
        $order->status = $to;
        $order->save();
        $order->statusHistory()->create(['from_status' => $from->value, 'to_status' => $to->value, 'user_id' => $actor->id, 'note' => $note, 'created_at' => now()]);
        SyncVersion::bump($order);
    }

    /** @return array<string, string> */
    private function smsVars(ProductionJob $job, SalesOrder $order): array
    {
        return [
            'customer' => (string) str($order->customer?->name)->before(' '),
            'order' => $order->invoice_number ?: $order->number,
            'item' => $job->title,
            'due' => $job->due_date?->format('j M Y') ?? '',
            'fulfilment' => $order->fulfilment_type?->value === 'deliver' ? 'delivery' : 'collection',
            'job' => $job->number,
        ];
    }

    private function log(ProductionJob $job, string $event, User $actor, ?string $note = null): void
    {
        JobStageLog::query()->create(['production_job_id' => $job->id, 'from_stage_id' => $job->production_stage_id, 'to_stage_id' => $job->production_stage_id, 'event' => $event, 'user_id' => $actor->id, 'note' => $note, 'created_at' => now()]);
    }
}
