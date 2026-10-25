<?php

namespace App\Models;

use App\Enums\JobPriority;
use App\Enums\ProductionJobStatus;
use App\Enums\ProductionSource;
use App\Enums\SupplierProgress;
use App\Models\Concerns\BelongsToLocation;
use App\Models\Concerns\TracksSyncVersion;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;

/**
 * One custom piece (or several identical ones) to make: in our workshop (moving through its
 * stages) or at a supplier (via a back-to-back PO). Completing a workshop job puts the finished
 * piece in stock, reserved for the order, valued at the job cost. Kept generic for Step 17
 * (production orders with bills of materials, work centres and capacity).
 */
#[Fillable([
    'number', 'location_id', 'sales_order_id', 'sales_order_line_id', 'custom_spec_id', 'product_variant_id', 'title',
    'quantity', 'source', 'status', 'production_stage_id', 'priority', 'due_date', 'assigned_to',
    'purchase_order_line_id', 'supplier_status', 'supplier_status_at', 'materials_cost', 'labour_cost',
    'overhead_bp', 'overhead_cost', 'total_cost', 'notes', 'started_at', 'completed_at', 'completed_by',
    'cancelled_at', 'created_by',
])]
class ProductionJob extends Model
{
    use BelongsToLocation, TracksSyncVersion;

    protected $attributes = [
        'source' => 'workshop',
        'status' => 'pending',
        'priority' => 1,
        'materials_cost' => 0,
        'labour_cost' => 0,
        'overhead_bp' => 0,
        'overhead_cost' => 0,
        'total_cost' => 0,
        'sync_version' => 0,
    ];

    protected function casts(): array
    {
        return [
            'source' => ProductionSource::class,
            'status' => ProductionJobStatus::class,
            'priority' => JobPriority::class,
            'supplier_status' => SupplierProgress::class,
            'quantity' => 'integer',
            'due_date' => 'date',
            'supplier_status_at' => 'datetime',
            'materials_cost' => 'integer',
            'labour_cost' => 'integer',
            'overhead_bp' => 'integer',
            'overhead_cost' => 'integer',
            'total_cost' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'sync_version' => 'integer',
        ];
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function salesOrderLine(): BelongsTo
    {
        return $this->belongsTo(SalesOrderLine::class);
    }

    public function spec(): BelongsTo
    {
        return $this->belongsTo(CustomSpec::class, 'custom_spec_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id')->withTrashed();
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** Compatibility alias for screens and integrations that call this the workshop location. */
    public function getWorkshopLocationIdAttribute(): ?int
    {
        return $this->location_id;
    }

    public function workshopLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(ProductionStage::class, 'production_stage_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function purchaseOrderLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderLine::class);
    }

    public function purchaseOrder(): HasOneThrough
    {
        return $this->hasOneThrough(PurchaseOrder::class, PurchaseOrderLine::class, 'id', 'id', 'purchase_order_line_id', 'purchase_order_id');
    }

    public function getPurchaseOrderIdAttribute(): ?int
    {
        return $this->purchaseOrderLine?->purchase_order_id;
    }

    public function stageLogs(): HasMany
    {
        return $this->hasMany(JobStageLog::class)->orderByDesc('id');
    }

    public function materials(): HasMany
    {
        return $this->hasMany(JobMaterial::class)->orderBy('id');
    }

    public function labour(): HasMany
    {
        return $this->hasMany(JobLabour::class)->orderBy('work_date')->orderBy('id');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [ProductionJobStatus::Pending->value, ProductionJobStatus::InProduction->value]);
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query->open()->whereNotNull('due_date')->whereDate('due_date', '<', today());
    }

    public function isOverdue(): bool
    {
        return $this->status->isOpen() && $this->due_date !== null && $this->due_date->lt(today());
    }

    /** Whole pieces being made. */
    public function pieces(): int
    {
        return max(1, intdiv($this->quantity, 1000));
    }
}
