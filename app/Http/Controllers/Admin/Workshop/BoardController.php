<?php

namespace App\Http\Controllers\Admin\Workshop;

use App\Enums\LocationType;
use App\Enums\ProductionJobStatus;
use App\Enums\ProductionSource;
use App\Http\Controllers\Controller;
use App\Models\Location;
use App\Models\ProductionJob;
use App\Models\ProductionStage;
use App\Services\Production\JobPresenter;
use App\Services\Production\ProductionService;
use App\Support\CurrentLocation;
use App\Support\SyncVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * The jobs board of the current workshop: a column per stage (plus Not started and Ready), drag a
 * card to move the job, kept live by polling (useStaffSync → sync()).
 */
class BoardController extends Controller
{
    /** Finished jobs stay on the Ready column this long. */
    private const READY_DAYS = 14;

    public function index(Request $request): Response
    {
        $location = CurrentLocation::get();
        $workshops = Location::accessibleTo($request->user())->where('type', LocationType::Workshop->value)->get()
            ->values()
            ->map(fn (Location $l) => ['id' => $l->id, 'name' => $l->name, 'code' => $l->code]);

        if (! $location || $location->type !== LocationType::Workshop) {
            return Inertia::render('Admin/Workshop/Board', ['location' => null, 'workshops' => $workshops, 'stages' => [], 'jobs' => [], 'version' => null]);
        }

        $jobs = $this->query($location->id)->with(JobPresenter::CARD_WITH)->orderByDesc('priority')->orderByRaw('due_date IS NULL')->orderBy('due_date')->get();

        return Inertia::render('Admin/Workshop/Board', [
            'location' => ['id' => $location->id, 'name' => $location->name, 'code' => $location->code],
            'workshops' => $workshops,
            'stages' => ProductionStage::forWorkshop($location->id)->map(fn (ProductionStage $s) => ['id' => $s->id, 'name' => $s->name])->values(),
            'jobs' => $jobs->map(fn (ProductionJob $j) => JobPresenter::card($j))->values(),
            'version' => SyncVersion::current($location->id),
        ]);
    }

    /** Polling: jobs at this workshop changed since ?since=V (removed ones come back as status cancelled/ready). */
    public function sync(Request $request): JsonResponse
    {
        $location = CurrentLocation::get();
        abort_unless($location && $location->type === LocationType::Workshop, 409, 'Switch to a workshop location.');
        $since = (int) $request->query('since', 0);

        $rows = ProductionJob::query()->where('location_id', $location->id)->where('source', ProductionSource::Workshop->value)
            ->where('sync_version', '>', $since)->with(JobPresenter::CARD_WITH)->orderBy('sync_version')->limit(101)->get();
        $more = $rows->count() > 100;
        $rows = $rows->take(100);

        return response()->json([
            'version' => $more ? $rows->last()->sync_version : max($since, SyncVersion::current($location->id)),
            'has_more' => $more,
            'jobs' => $rows->map(fn (ProductionJob $j) => JobPresenter::card($j))->values(),
        ]);
    }

    public function move(Request $request, ProductionJob $job, ProductionService $production): JsonResponse
    {
        $this->authorizeJob($request, $job);
        $data = $request->validate(['stage_id' => ['nullable', 'integer'], 'note' => ['nullable', 'string', 'max:500']]);

        try {
            $job = $production->moveToStage($job, $data['stage_id'] ?? null, $request->user(), $data['note'] ?? null);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['job' => JobPresenter::card($job->load(JobPresenter::CARD_WITH))]);
    }

    private function query(int $locationId)
    {
        return ProductionJob::query()->where('location_id', $locationId)->where('source', ProductionSource::Workshop->value)
            ->where(fn ($q) => $q->whereIn('status', [ProductionJobStatus::Pending->value, ProductionJobStatus::InProduction->value])
                ->orWhere(fn ($r) => $r->where('status', ProductionJobStatus::Ready->value)->where('completed_at', '>=', now()->subDays(self::READY_DAYS))));
    }

    private function authorizeJob(Request $request, ProductionJob $job): void
    {
        abort_unless($request->user()->can('work-on-jobs') && $request->user()->worksAt($job->location_id), 403);
    }
}
