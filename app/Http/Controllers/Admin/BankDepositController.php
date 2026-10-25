<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ChequeStatus;
use App\Enums\PaymentRecordStatus;
use App\Exceptions\CashActionRefused;
use App\Http\Controllers\Controller;
use App\Models\BankDeposit;
use App\Models\Payment;
use App\Models\Shift;
use App\Services\Cash\BankDepositService;
use App\Support\CurrentLocation;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Bank deposits for the current location: cash from the safe or a shift, plus pending cheques. */
class BankDepositController extends Controller
{
    public function index(Request $request, BankDepositService $deposits): Response
    {
        Gate::authorize('override');
        $locationId = $this->locationId($request);

        return Inertia::render('Admin/Cash/Deposits', [
            'deposits' => BankDeposit::query()->where('location_id', $locationId)->with(['user:id,name', 'shift.user:id,name'])
                ->latest('id')->paginate(25)->withQueryString()
                ->through(fn (BankDeposit $d) => [
                    'id' => $d->id,
                    'deposited_on' => $d->deposited_on->toDateString(),
                    'bank_account' => $d->bank_account,
                    'slip_reference' => $d->slip_reference,
                    'source' => $d->source,
                    'shift' => $d->shift ? "#{$d->shift->id} {$d->shift->user?->name}" : null,
                    'cash_amount' => $d->cash_amount,
                    'cheque_amount' => $d->cheque_amount,
                    'cheque_count' => $d->cheque_count,
                    'total' => $d->total(),
                    'note' => $d->note,
                    'by' => $d->user?->name,
                ]),
            'safeBalance' => $deposits->safeBalance($locationId),
            'openShifts' => Shift::query()->where('location_id', $locationId)->open()->with(['user:id,name', 'register:id,name'])->get()
                ->map(fn (Shift $s) => ['id' => $s->id, 'label' => "{$s->register?->name} · {$s->user?->name}"])->values(),
            'pendingCheques' => Payment::query()->where('location_id', $locationId)
                ->where('status', PaymentRecordStatus::Completed->value)
                ->where('cheque_status', ChequeStatus::Pending->value)
                ->with('customer:id,name')->orderBy('cheque_date')->get()
                ->map(fn (Payment $p) => [
                    'id' => $p->id,
                    'number' => $p->number,
                    'cheque_number' => $p->cheque_number,
                    'bank' => $p->cheque_bank,
                    'cheque_date' => $p->cheque_date?->toDateString(),
                    'due' => $p->cheque_date !== null && $p->cheque_date->lte(today()),
                    'customer' => $p->customer?->name,
                    'amount' => $p->amount,
                ])->values(),
        ]);
    }

    public function store(Request $request, BankDepositService $deposits): RedirectResponse
    {
        Gate::authorize('override');
        $data = $request->validate([
            'source' => ['required', 'in:safe,shift'],
            'shift_id' => ['nullable', 'integer'],
            'cash_amount' => ['nullable', 'numeric', 'decimal:0,2', 'min:0'],
            'cheque_ids' => ['array'],
            'cheque_ids.*' => ['integer'],
            'bank_account' => ['required', 'string', 'max:120'],
            'slip_reference' => ['required', 'string', 'max:60'],
            'deposited_on' => ['required', 'date', 'before_or_equal:today'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $deposit = $deposits->record($this->locationId($request), [
                ...$data,
                'cash_amount' => filled($data['cash_amount'] ?? null) ? Money::fromDecimal((string) $data['cash_amount'])->cents : 0,
            ], $request->user());
        } catch (CashActionRefused $e) {
            throw ValidationException::withMessages(['deposit' => $e->getMessage()]);
        }

        return back()->with('success', "Deposit {$deposit->slip_reference} of ".Money::fromCents($deposit->total())->format().' recorded.');
    }

    private function locationId(Request $request): int
    {
        return CurrentLocation::id() ?? (int) $request->user()->home_location_id;
    }
}
