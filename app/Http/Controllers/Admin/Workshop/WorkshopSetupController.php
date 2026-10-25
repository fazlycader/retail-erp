<?php

namespace App\Http\Controllers\Admin\Workshop;

use App\Enums\LocationType;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\CompanySetting;
use App\Models\Location;
use App\Models\ProductionStage;
use App\Models\SmsMessage;
use App\Support\BasisPoints;
use App\Support\CurrentLocation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class WorkshopSetupController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('manage-workshop');

        $user = $request->user();
        $settings = CompanySetting::current();

        $workshops = Location::accessibleTo($user)
            ->where('type', LocationType::Workshop->value)
            ->get()
            ->values()
            ->map(fn (Location $l) => ['id' => $l->id, 'name' => $l->name, 'code' => $l->code]);

        $current = CurrentLocation::get();
        $selectedWorkshopId = $request->integer('location_id')
            ?: (($current && $current->type === LocationType::Workshop) ? $current->id : ($settings->workshop_location_id ?: ($workshops->first()['id'] ?? null)));

        $stages = [];
        if ($selectedWorkshopId && $user->worksAt($selectedWorkshopId)) {
            ProductionStage::forWorkshop($selectedWorkshopId);
            $stages = ProductionStage::query()
                ->where('location_id', $selectedWorkshopId)
                ->ordered()
                ->get()
                ->map(fn (ProductionStage $s) => [
                    'id' => $s->id,
                    'location_id' => $s->location_id,
                    'name' => $s->name,
                    'sort_order' => $s->sort_order,
                    'is_active' => $s->is_active,
                ])
                ->values()
                ->all();
        }

        $templates = [];
        foreach (CompanySetting::SMS_TEMPLATES as $key => $defaultText) {
            $templates[$key] = $settings->smsTemplate($key) ?? $defaultText;
        }

        $recentSms = SmsMessage::query()
            ->with('customer:id,name')
            ->latest('id')
            ->limit(25)
            ->get()
            ->map(fn (SmsMessage $m) => [
                'id' => $m->id,
                'template' => $m->template,
                'phone' => $m->phone,
                'customer_name' => $m->customer?->name,
                'body' => $m->body,
                'status' => $m->status,
                'error' => $m->error,
                'sent_at' => $m->sent_at?->toIso8601String(),
                'created_at' => $m->created_at?->toIso8601String(),
            ]);

        return Inertia::render('Admin/Workshop/Setup', [
            'workshops' => $workshops,
            'selectedWorkshopId' => $selectedWorkshopId,
            'stages' => $stages,
            'settings' => [
                'workshop_location_id' => $settings->workshop_location_id,
                'overhead_percent' => BasisPoints::toPercent((int) $settings->workshop_overhead_bp),
                'workshop_lead_days' => (int) $settings->workshop_lead_days,
                'sms_enabled' => (bool) $settings->sms_enabled,
                'sms_templates' => $templates,
            ],
            'defaultTemplates' => CompanySetting::SMS_TEMPLATES,
            'recentSms' => $recentSms,
        ]);
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        Gate::authorize('manage-workshop');

        $data = $request->validate([
            'workshop_location_id' => [
                'nullable',
                'integer',
                Rule::exists('locations', 'id')->where('type', LocationType::Workshop->value),
            ],
            'overhead_percent' => ['required', 'string', 'max:10'],
            'workshop_lead_days' => ['required', 'integer', 'min:1', 'max:365'],
            'sms_enabled' => ['boolean'],
            'sms_templates' => ['required', 'array'],
            ...collect(array_keys(CompanySetting::SMS_TEMPLATES))
                ->mapWithKeys(fn (string $key) => ["sms_templates.{$key}" => ['required', 'string', 'max:500']])
                ->all(),
        ]);

        $settings = CompanySetting::current();
        $overheadBp = BasisPoints::fromPercent($data['overhead_percent']);

        $settings->update([
            'workshop_location_id' => $data['workshop_location_id'] ?? null,
            'workshop_overhead_bp' => $overheadBp,
            'workshop_lead_days' => (int) $data['workshop_lead_days'],
            'sms_enabled' => (bool) ($data['sms_enabled'] ?? false),
            'sms_templates' => collect(CompanySetting::SMS_TEMPLATES)->keys()
                ->mapWithKeys(fn (string $key) => [$key => trim($data['sms_templates'][$key])])->all(),
        ]);
        CompanySetting::flushCache();

        AuditLog::record('workshop.settings_updated', $settings, 'Updated workshop and SMS settings', null, [
            'workshop_location_id' => $settings->workshop_location_id,
            'workshop_overhead_bp' => $overheadBp,
            'workshop_lead_days' => $settings->workshop_lead_days,
            'sms_enabled' => $settings->sms_enabled,
        ]);

        return back()->with('success', 'Workshop settings saved.');
    }

    public function storeStage(Request $request): RedirectResponse
    {
        Gate::authorize('manage-workshop');

        $data = $request->validate([
            'location_id' => [
                'required',
                'integer',
                Rule::exists('locations', 'id')->where('type', LocationType::Workshop->value),
            ],
            'name' => ['required', 'string', 'max:60'],
        ]);

        abort_unless($request->user()->worksAt((int) $data['location_id']), 403);

        $maxSort = (int) ProductionStage::query()->where('location_id', (int) $data['location_id'])->max('sort_order');

        $stage = ProductionStage::query()->create([
            'location_id' => (int) $data['location_id'],
            'name' => trim($data['name']),
            'sort_order' => $maxSort + 10,
            'is_active' => true,
        ]);

        AuditLog::record('workshop.stage_created', $stage, "Added stage {$stage->name}");

        return back()->with('success', "Stage {$stage->name} added.");
    }

    public function updateStage(Request $request, ProductionStage $stage): RedirectResponse
    {
        Gate::authorize('manage-workshop');
        abort_unless($request->user()->worksAt($stage->location_id), 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'is_active' => ['boolean'],
        ]);

        $stage->update([
            'name' => trim($data['name']),
            'is_active' => (bool) ($data['is_active'] ?? $stage->is_active),
        ]);

        return back()->with('success', "Stage {$stage->name} updated.");
    }

    public function reorderStages(Request $request): RedirectResponse
    {
        Gate::authorize('manage-workshop');

        $data = $request->validate([
            'location_id' => ['required', 'integer', 'exists:locations,id'],
            'stage_ids' => ['required', 'array', 'min:1'],
            'stage_ids.*' => ['integer', 'exists:production_stages,id'],
        ]);

        $locationId = (int) $data['location_id'];
        abort_unless($request->user()->worksAt($locationId), 403);

        DB::transaction(function () use ($locationId, $data) {
            foreach (array_values($data['stage_ids']) as $i => $id) {
                ProductionStage::query()
                    ->whereKey((int) $id)
                    ->where('location_id', $locationId)
                    ->update(['sort_order' => ($i + 1) * 10]);
            }
        });

        return back()->with('success', 'Stage order saved.');
    }
}
