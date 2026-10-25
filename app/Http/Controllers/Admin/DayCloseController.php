<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\CashActionRefused;
use App\Http\Controllers\Controller;
use App\Models\CompanySetting;
use App\Models\DayClose;
use App\Services\Cash\DayCloseService;
use App\Services\Cash\DayReport;
use App\Support\BusinessDay;
use App\Support\CurrentLocation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/** End of day for the current location: checklist, close (freeze the report, lock the day), print. */
class DayCloseController extends Controller
{
    public function show(Request $request, DayCloseService $days): Response
    {
        Gate::authorize('override');
        $locationId = $this->locationId($request);
        $date = $this->date($request, $locationId);
        $day = DayClose::query()->where('location_id', $locationId)->whereDate('business_date', $date)->with('closedBy:id,name')->first();
        $closed = $day?->isClosed() ?? false;

        return Inertia::render('Admin/Cash/EndOfDay', [
            'date' => $date,
            'today' => BusinessDay::current($locationId),
            'closed' => $closed ? ['by' => $day->closedBy?->name, 'at' => $day->closed_at?->toIso8601String(), 'warnings' => $day->warnings ?? []] : null,
            'checklist' => $closed ? null : $days->checklist($locationId, $date),
            'report' => $closed ? $day->report : DayReport::build($locationId, $date),
            'recent' => DayClose::query()->where('location_id', $locationId)->where('status', 'closed')->latest('business_date')->limit(10)->get()
                ->map(fn (DayClose $d) => $d->business_date->toDateString())->values(),
        ]);
    }

    public function close(Request $request, DayCloseService $days): JsonResponse
    {
        Gate::authorize('override');
        $data = $request->validate(['date' => ['required', 'date_format:Y-m-d'], 'accept_warnings' => ['boolean']]);

        try {
            $days->close($this->locationId($request), $data['date'], $request->user(), $request->boolean('accept_warnings'));
        } catch (CashActionRefused $e) {
            return $e->toResponse();
        }

        return response()->json(['message' => "{$data['date']} is closed and locked."]);
    }

    public function print(Request $request): HttpResponse
    {
        Gate::authorize('override');
        $locationId = $this->locationId($request);
        $date = $this->date($request, $locationId);
        $day = DayClose::query()->where('location_id', $locationId)->whereDate('business_date', $date)->first();

        return response()->view('documents.day-report', [
            'report' => $day?->isClosed() ? $day->report : DayReport::build($locationId, $date),
            'closed' => $day?->isClosed() ?? false,
            'company' => CompanySetting::current(),
            'autoPrint' => $request->boolean('print'),
        ]);
    }

    private function date(Request $request, int $locationId): string
    {
        $date = (string) $request->query('date', '');

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? $date : BusinessDay::current($locationId);
    }

    private function locationId(Request $request): int
    {
        return CurrentLocation::id() ?? (int) $request->user()->home_location_id;
    }
}
