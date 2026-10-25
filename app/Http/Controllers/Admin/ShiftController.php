<?php

namespace App\Http\Controllers\Admin;

use App\Enums\CashMovementType;
use App\Enums\PaymentMethodCode;
use App\Enums\ShiftStatus;
use App\Exceptions\ApprovalRefused;
use App\Exceptions\CashActionRefused;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\CashMovement;
use App\Models\CashMovementCategory;
use App\Models\CompanySetting;
use App\Models\PaymentMethod;
use App\Models\Register;
use App\Models\Shift;
use App\Models\User;
use App\Services\Cash\CashCalculator;
use App\Services\Cash\Denominations;
use App\Services\Cash\ShiftReport;
use App\Services\Cash\ShiftService;
use App\Support\CurrentLocation;
use App\Support\DeviceRegister;
use App\Support\ManagerApproval;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The cashier's shift screen (open, pay-in/out, drops, X-report, blind close) and the managers'
 * shift list for the current location (approve variances, force-close, Z-reports).
 */
class ShiftController extends Controller
{
    public function current(Request $request, ShiftService $shifts): Response
    {
        $user = $request->user();
        $locationId = $this->locationId($request);
        $shift = $shifts->openFor($user);
        $settings = CompanySetting::current();
        $blind = $settings->blind_close && ! $user->role->canOverride();

        $report = $shift ? ShiftReport::build($shift, 'x') : null;

        // Blind close: cashiers don't see what the drawer should hold until they've counted.
        if ($report && $blind) {
            $report = ShiftReport::blind($report);
        }

        return Inertia::render('Admin/Cash/Current', [
            'shift' => $shift ? [
                'id' => $shift->id,
                'register' => $shift->register()->value('name'),
                'location' => $shift->location()->value('name'),
                'elsewhere' => $shift->location_id !== $locationId,
                'opened_at' => $shift->opened_at->toIso8601String(),
                'business_date' => $shift->business_date->toDateString(),
                'opening_float' => $shift->opening_float,
            ] : null,
            'report' => $report,
            'lastClosed' => $shift ? null : $this->lastClosed($user),
            'registers' => Register::query()->where('location_id', $locationId)->where('is_active', true)->with('openShift.user:id,name')->orderBy('name')->get()
                ->map(fn (Register $r) => ['id' => $r->id, 'name' => $r->name, 'busy' => $r->openShift?->user?->name])->values(),
            'deviceRegisterId' => DeviceRegister::id($request, $locationId),
            'denominations' => Denominations::options(),
            'categories' => CashMovementCategory::query()->where('is_active', true)->ordered()->get(['id', 'name', 'applies_to']),
            'movementTypes' => CashMovementType::options(),
            'methods' => $this->terminalMethods(),
            'approvers' => $user->role->canOverride() ? [] : ManagerApproval::approvers($shift?->location_id ?? $locationId),
            'settings' => [
                'blind_close' => $blind,
                'variance_threshold' => $settings->variance_approval_threshold,
                'payout_threshold' => $settings->payout_approval_threshold,
                'require_shift' => $settings->require_shift_for_payments,
            ],
            'manager' => $user->role->canOverride(),
        ]);
    }

    public function useRegister(Request $request, Register $register): RedirectResponse
    {
        abort_unless($register->is_active && $register->location_id === $this->locationId($request), 403);
        DeviceRegister::remember($register);

        return back()->with('success', "This device is now {$register->name}.");
    }

    public function open(Request $request, ShiftService $shifts): JsonResponse
    {
        $data = $request->validate([
            'register_id' => ['required', 'integer'],
            'counts' => ['nullable', 'array'],
            'counts.*' => ['integer', 'min:0', 'max:100000'],
            'total' => ['nullable', 'integer', 'min:0', 'max:100000000'],
        ]);
        $register = Register::query()->where('location_id', $this->locationId($request))->findOrFail($data['register_id']);

        return $this->attempt(function () use ($request, $shifts, $register, $data) {
            $shift = $shifts->open($register, $request->user(), $data['counts'] ?? null, $data['total'] ?? null);
            DeviceRegister::remember($register);

            return ['message' => "Shift opened on {$register->name} with ".money($shift->opening_float)->format().'.', 'shift_id' => $shift->id];
        });
    }

    public function movement(Request $request, ShiftService $shifts): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::enum(CashMovementType::class)],
            'amount' => ['required', 'integer', 'min:1', 'max:100000000'],
            'category_id' => ['nullable', 'integer'],
            'reason' => ['required', 'string', 'max:255'],
            'approver_id' => ['nullable', 'integer'],
            'approver_pin' => ['nullable', 'string', 'regex:/^\d{4,6}$/'],
        ]);
        $user = $request->user();
        $shift = $shifts->openFor($user) ?? abort(response()->json(['message' => 'Open a shift first.', 'reason' => 'shift_required'], 422));

        return $this->attempt(function () use ($request, $shifts, $shift, $user, $data) {
            $type = CashMovementType::from($data['type']);
            $approver = null;

            if ($type === CashMovementType::PayOut && $data['amount'] > CompanySetting::current()->payout_approval_threshold && ! $user->role->canOverride()) {
                $approver = ManagerApproval::verify($user, $shift->location_id, $request->integer('approver_id') ?: null, $request->input('approver_pin'), 'shift.pay_out', $shift, 'a pay-out');
            }

            $movement = $shifts->movement($shift, $user, $type, $data['amount'], $data['category_id'] ?? null, $data['reason'], $approver);

            return ['message' => $type->label().' of '.money($data['amount'])->format().' recorded.', 'slip_url' => route('cash.movements.slip', $movement)];
        });
    }

    public function close(Request $request, ShiftService $shifts): JsonResponse
    {
        $data = $this->closeRules($request);
        $shift = $shifts->openFor($request->user()) ?? abort(response()->json(['message' => 'You have no open shift.', 'reason' => 'conflict'], 409));

        return $this->attempt(function () use ($request, $shifts, $shift, $data) {
            $user = $request->user();
            // A manager signing off a big variance now (cashiers: manager PIN; managers: themselves).
            $approver = null;

            if ($request->filled('approver_pin') || $user->role->canOverride()) {
                $approver = $user->role->canOverride() ? $user : ManagerApproval::verify($user, $shift->location_id, $request->integer('approver_id') ?: null, $request->input('approver_pin'), 'shift.close', $shift, 'a shift variance');
            }

            $closed = $shifts->close($shift, $user, $data['counts'] ?? null, $data['total'] ?? null, $data['terminal_totals'] ?? null, $data['note'] ?? null, $approver);

            return [
                'message' => $closed->status === ShiftStatus::Approved ? 'Shift closed.' : 'Shift closed. The difference needs a manager\'s approval.',
                'shift' => $this->row($closed),
                'report' => $closed->z_report,
            ];
        });
    }

    // ---- Managers (current location) ---------------------------------------------------------

    public function index(Request $request): Response
    {
        Gate::authorize('override');

        $rows = Shift::query()->where('location_id', $this->locationId($request))
            ->with(['register:id,name', 'user:id,name', 'approvedBy:id,name'])
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->orderByRaw("CASE status WHEN 'open' THEN 0 WHEN 'closed' THEN 1 ELSE 2 END")->latest('id')
            ->paginate(30)->withQueryString()
            ->through(fn (Shift $s) => $this->row($s));

        return Inertia::render('Admin/Cash/Shifts', [
            'shifts' => $rows,
            'filters' => ['status' => $request->query('status')],
            'statuses' => ShiftStatus::options(),
        ]);
    }

    public function show(Request $request, Shift $shift): Response
    {
        $this->authorizeShift($request->user(), $shift);
        $report = $this->report($request->user(), $shift);

        return Inertia::render('Admin/Cash/Show', [
            'shift' => $this->row($shift->load(['register:id,name', 'user:id,name', 'approvedBy:id,name'])),
            'report' => $report,
            'denominations' => Denominations::options(),
            'methods' => PaymentMethod::query()->get(['id', 'name']),
            'manager' => $request->user()->role->canOverride() && $request->user()->worksAt($shift->location_id),
        ]);
    }

    /** X-report (open shift) or the frozen Z-report, on an 80 mm page for the browser to print. */
    public function print(Request $request, Shift $shift): HttpResponse
    {
        $this->authorizeShift($request->user(), $shift);

        if ($shift->z_report) {
            AuditLog::record('shift.reprint', $shift, 'Printed the Z-report', null, null, $request->user());
        }

        return response()->view('documents.shift-report', [
            'report' => $this->report($request->user(), $shift),
            'company' => CompanySetting::current(),
            'autoPrint' => $request->boolean('print'),
        ]);
    }

    /** A pay-in / pay-out / drop slip to sign. */
    public function slip(Request $request, CashMovement $movement): HttpResponse
    {
        $movement->load(['shift', 'user:id,name', 'approvedBy:id,name', 'location:id,name']);
        $this->authorizeShift($request->user(), $movement->shift);

        return response()->view('documents.cash-slip', [
            'movement' => $movement,
            'company' => CompanySetting::current(),
            'autoPrint' => $request->boolean('print'),
        ]);
    }

    public function approve(Request $request, Shift $shift, ShiftService $shifts): JsonResponse
    {
        Gate::authorize('override');
        abort_unless($request->user()->worksAt($shift->location_id), 403);
        $data = $request->validate(['note' => ['required', 'string', 'max:500']]);

        return $this->attempt(fn () => ['message' => 'Shift approved.', 'shift' => $this->row($shifts->approve($shift, $request->user(), $data['note']))]);
    }

    public function forceClose(Request $request, Shift $shift, ShiftService $shifts): JsonResponse
    {
        Gate::authorize('override');
        abort_unless($request->user()->worksAt($shift->location_id), 403);
        $data = [...$this->closeRules($request), ...$request->validate(['reason' => ['required', 'string', 'max:255']])];

        return $this->attempt(function () use ($request, $shifts, $shift, $data) {
            $closed = $shifts->close($shift, $request->user(), $data['counts'] ?? null, $data['total'] ?? null, $data['terminal_totals'] ?? null, $data['note'] ?? $data['reason'], $request->user(), $data['reason']);

            return ['message' => 'Shift force-closed.', 'shift' => $this->row($closed)];
        });
    }

    // ---- Helpers -----------------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function closeRules(Request $request): array
    {
        return $request->validate([
            'counts' => ['nullable', 'array'],
            'counts.*' => ['integer', 'min:0', 'max:100000'],
            'total' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'terminal_totals' => ['nullable', 'array'],
            'terminal_totals.*' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'note' => ['nullable', 'string', 'max:500'],
            'approver_id' => ['nullable', 'integer'],
            'approver_pin' => ['nullable', 'string', 'regex:/^\d{4,6}$/'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function report(User $user, Shift $shift): array
    {
        $report = $shift->z_report ?? ShiftReport::build($shift, 'x');

        return $shift->isOpen() && CompanySetting::current()->blind_close && ! $user->role->canOverride()
            ? ShiftReport::blind($report)
            : $report;
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Shift $s): array
    {
        return [
            'id' => $s->id,
            'register' => $s->register?->name,
            'cashier' => $s->user?->name,
            'business_date' => $s->business_date->toDateString(),
            'opened_at' => $s->opened_at->toIso8601String(),
            'closed_at' => $s->closed_at?->toIso8601String(),
            'status' => $s->status->value,
            'status_label' => $s->status->label(),
            'opening_float' => $s->opening_float,
            'expected' => $s->isOpen() ? CashCalculator::expected($s)['expected'] : $s->expected_cash,
            'counted' => $s->counted_cash,
            'variance' => $s->variance,
            'note' => $s->closing_note,
            'force_close_reason' => $s->force_close_reason,
            'approved_by' => $s->approvedBy?->name,
            'approval_note' => $s->approval_note,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function lastClosed(User $user): ?array
    {
        $shift = Shift::query()->where('user_id', $user->id)->whereNotNull('closed_at')->latest('id')->with(['register:id,name', 'user:id,name', 'approvedBy:id,name'])->first();

        return $shift ? $this->row($shift) : null;
    }

    /** Own shift, or a manager/admin who works at the shift's location. Never another location's. */
    private function authorizeShift(User $user, Shift $shift): void
    {
        abort_unless($user->worksAt($shift->location_id) && ($user->role->canOverride() || $shift->user_id === $user->id), 403);
    }

    private function locationId(Request $request): int
    {
        return CurrentLocation::id() ?? (int) $request->user()->home_location_id;
    }

    /**
     * Methods settled through a bank terminal, to compare with its settlement slip at close.
     *
     * @return Collection<int, array{id: int, name: string}>
     */
    private function terminalMethods()
    {
        return PaymentMethod::query()->active()
            ->whereIn('code', [PaymentMethodCode::Card->value, PaymentMethodCode::LankaQr->value])
            ->get()->map(fn (PaymentMethod $m) => ['id' => $m->id, 'name' => $m->name])->values();
    }

    private function attempt(Closure $action): JsonResponse
    {
        try {
            return response()->json($action());
        } catch (ApprovalRefused $e) {
            return $e->toResponse();
        } catch (CashActionRefused $e) {
            return $e->toResponse();
        }
    }
}
