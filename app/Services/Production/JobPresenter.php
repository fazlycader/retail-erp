<?php

namespace App\Services\Production;

use App\Models\JobLabour;
use App\Models\JobMaterial;
use App\Models\JobStageLog;
use App\Models\ProductionJob;
use App\Models\User;
use App\Support\Quantity;

/**
 * What the workshop screens get for a job. Prices and costs are only added for users who may see
 * job costs (admins, managers): workshop staff never receive them, not even hidden.
 */
final class JobPresenter
{
    /** Eager loads for card(). */
    public const CARD_WITH = ['salesOrder:id,number,invoice_number,customer_id,fulfilment_type', 'salesOrder.customer:id,name', 'assignee:id,name', 'spec:id,attachments', 'stage:id,name'];

    /** @return array<string, mixed> */
    public static function card(ProductionJob $job): array
    {
        $thumb = collect($job->spec?->attachments ?? [])->first(fn (array $a) => isset($a['thumb']));

        return [
            'id' => $job->id,
            'number' => $job->number,
            'title' => $job->title,
            'pieces' => $job->pieces(),
            'status' => $job->status->value,
            'status_label' => $job->status->label(),
            'source' => $job->source->value,
            'stage_id' => $job->production_stage_id,
            'stage' => $job->stage?->name,
            'priority' => $job->priority->value,
            'priority_label' => $job->priority->label(),
            'due_date' => $job->due_date?->toDateString(),
            'overdue' => $job->isOverdue(),
            'assignee' => $job->assignee?->name,
            'customer' => $job->salesOrder?->customer?->name,
            'order' => $job->salesOrder?->invoice_number ?: $job->salesOrder?->number,
            'thumb' => $thumb ? route('private-files.show', ['path' => $thumb['thumb']]) : null,
            'supplier_status' => $job->supplier_status?->label(),
            'version' => $job->sync_version,
        ];
    }

    /** @return array<string, mixed> */
    public static function detail(ProductionJob $job, User $user): array
    {
        $costs = $user->can('view-job-costs');
        $job->loadMissing([
            ...self::CARD_WITH, 'spec.baseProduct:id,name', 'location:id,name,code,type', 'salesOrderLine:id,quantity,fulfilled_qty,reserved_qty,fulfil_location_id',
            'materials.variant.product:id,name,unit', 'materials.user:id,name', 'labour', 'stageLogs.user:id,name', 'stageLogs.toStage:id,name', 'stageLogs.fromStage:id,name',
            'purchaseOrderLine.purchaseOrder.supplier:id,name',
        ]);

        $materials = $job->materials->groupBy('product_variant_id')->map(function ($rows) use ($costs) {
            $variant = $rows->first()->variant;
            $unit = $variant?->product?->unit ?? 'pcs';
            $net = (int) $rows->sum(fn (JobMaterial $m) => $m->signedQuantity());

            return [
                'variant_id' => $variant?->id,
                'sku' => $variant?->sku,
                'name' => trim(($variant?->product?->name ?? '').($variant?->name ? ' — '.$variant->name : '')),
                'unit' => $unit,
                'net' => Quantity::toDecimal($net),
                'net_label' => Quantity::format($net, $unit),
                ...($costs ? ['cost' => (int) $rows->sum(fn (JobMaterial $m) => $m->signedCost())] : []),
            ];
        })->values();

        return [
            ...self::card($job),
            'location' => ['id' => $job->location_id, 'name' => $job->location?->name, 'code' => $job->location?->code],
            'notes' => $job->notes,
            'assigned_to' => $job->assigned_to,
            'sales_order_id' => $job->sales_order_id,
            'fulfilment' => $job->salesOrder?->fulfilment_type?->label(),
            'started_at' => $job->started_at?->toIso8601String(),
            'completed_at' => $job->completed_at?->toIso8601String(),
            'spec' => $job->spec?->toPayload(),
            'materials' => $materials,
            'material_log' => $job->materials->sortByDesc('id')->take(50)->values()->map(fn (JobMaterial $m) => [
                'id' => $m->id, 'type' => $m->type, 'sku' => $m->variant?->sku, 'quantity' => Quantity::format($m->quantity, $m->variant?->product?->unit ?? 'pcs'),
                'by' => $m->user?->name, 'at' => $m->created_at?->toIso8601String(), 'note' => $m->note,
                ...($costs ? ['cost' => $m->cost] : []),
            ]),
            'labour' => $costs ? $job->labour->map(fn (JobLabour $l) => [
                'id' => $l->id, 'worker' => $l->worker_name, 'date' => $l->work_date?->toDateString(),
                'hours' => $l->minutes !== null ? round($l->minutes / 60, 2) : null, 'rate' => $l->hourly_rate, 'amount' => $l->amount, 'note' => $l->note,
            ]) : null,
            'cost' => $costs ? [
                'materials' => $job->materials_cost, 'labour' => $job->labour_cost, 'overhead_bp' => $job->overhead_bp,
                'overhead' => $job->overhead_cost, 'total' => $job->total_cost,
            ] : null,
            'log' => $job->stageLogs->take(100)->map(fn (JobStageLog $l) => [
                'id' => $l->id, 'event' => $l->event, 'from' => $l->fromStage?->name, 'to' => $l->toStage?->name,
                'note' => $l->note, 'by' => $l->user?->name, 'at' => $l->created_at?->toIso8601String(),
                'photos' => collect($l->photos ?? [])->map(fn (array $p) => ['url' => route('private-files.show', ['path' => $p['path']]), 'thumb' => route('private-files.show', ['path' => $p['thumb'] ?? $p['path']])])->all(),
            ]),
            'supplier' => $job->purchase_order_line_id ? [
                'name' => $job->purchaseOrderLine?->purchaseOrder?->supplier?->name,
                'po_id' => $job->purchaseOrderLine?->purchase_order_id,
                'po_number' => $job->purchaseOrderLine?->purchaseOrder?->number,
                'status' => $job->supplier_status?->value,
                'received' => Quantity::toDecimal((int) $job->purchaseOrderLine?->received_qty),
                ...($costs ? ['unit_cost' => (int) $job->purchaseOrderLine?->unit_cost] : []),
            ] : null,
            'waiting_at_workshop' => $job->status->value === 'ready' && $job->salesOrderLine && $job->salesOrderLine->fulfil_location_id === $job->location_id
                && $job->salesOrderLine->fulfilled_qty < $job->salesOrderLine->quantity,
        ];
    }
}
