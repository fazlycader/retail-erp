<?php

namespace App\Services\Cash;

use App\Enums\ChequeStatus;
use App\Enums\PaymentRecordStatus;
use App\Enums\SalesOrderStatus;
use App\Enums\ShiftStatus;
use App\Enums\TransferStatus;
use App\Exceptions\CashActionRefused;
use App\Jobs\SendDailyReport;
use App\Models\AuditLog;
use App\Models\DayClose;
use App\Models\Payment;
use App\Models\SalesOrder;
use App\Models\Shift;
use App\Models\Transfer;
use App\Models\User;
use App\Services\Reports\DailyOwnerReport;
use App\Services\Reports\SummaryBuilder;
use App\Support\BusinessDay;
use Illuminate\Support\Facades\DB;

/**
 * End of day per location: checklist (open shifts block; shifts waiting for approval, unprinted
 * invoices, pending cheques due by that day and transfers on their way here warn), then freeze
 * the day report and lock the day (DayLock: edits admin-only and audited).
 */
class DayCloseService
{
    public function __construct(private SummaryBuilder $summaries, private DailyOwnerReport $owner) {}

    /**
     * @return array{blockers: array<int, array{key: string, message: string, count: int}>, warnings: array<int, array{key: string, message: string, count: int}>}
     */
    public function checklist(int $locationId, string $date): array
    {
        $blockers = [];
        $warnings = [];

        $openShifts = Shift::query()->where('location_id', $locationId)->open()->whereDate('business_date', '<=', $date)->count();
        if ($openShifts) {
            $blockers[] = ['key' => 'open_shifts', 'message' => "{$openShifts} shift(s) still open. Close them (or force-close) first.", 'count' => $openShifts];
        }

        $unapproved = Shift::query()->where('location_id', $locationId)->where('status', ShiftStatus::Closed->value)->whereDate('business_date', $date)->count();
        if ($unapproved) {
            $warnings[] = ['key' => 'unapproved_shifts', 'message' => "{$unapproved} shift(s) with a cash difference waiting for approval.", 'count' => $unapproved];
        }

        $unprinted = SalesOrder::query()->where('location_id', $locationId)->whereDate('business_date', $date)
            ->whereNotNull('invoice_number')->whereNull('invoice_printed_at')
            ->where('status', '!=', SalesOrderStatus::Cancelled->value)->count();
        if ($unprinted) {
            $warnings[] = ['key' => 'unprinted_invoices', 'message' => "{$unprinted} invoice(s) never printed.", 'count' => $unprinted];
        }

        $cheques = Payment::query()->where('location_id', $locationId)->where('status', PaymentRecordStatus::Completed->value)
            ->where('cheque_status', ChequeStatus::Pending->value)->whereDate('cheque_date', '<=', $date)->count();
        if ($cheques) {
            $warnings[] = ['key' => 'cheques_due', 'message' => "{$cheques} pending cheque(s) dated on or before {$date}: deposit them.", 'count' => $cheques];
        }

        $transfers = Transfer::query()->where('to_location_id', $locationId)->where('status', TransferStatus::Dispatched->value)->count();
        if ($transfers) {
            $warnings[] = ['key' => 'open_transfers', 'message' => "{$transfers} transfer(s) dispatched to this location but not received.", 'count' => $transfers];
        }

        return ['blockers' => $blockers, 'warnings' => $warnings];
    }

    public function close(int $locationId, string $date, User $by, bool $acceptWarnings): DayClose
    {
        if ($date > BusinessDay::current($locationId)) {
            throw new CashActionRefused('That business day has not started yet.');
        }

        $check = $this->checklist($locationId, $date);

        if ($check['blockers']) {
            throw new CashActionRefused($check['blockers'][0]['message'], 'blocked');
        }

        if ($check['warnings'] && ! $acceptWarnings) {
            throw new CashActionRefused('Review the warnings and confirm to close the day anyway.', 'warnings');
        }

        $report = DayReport::build($locationId, $date);

        $day = DB::transaction(function () use ($locationId, $date, $by, $check, $report) {
            $day = DayClose::query()->where('location_id', $locationId)->whereDate('business_date', $date)->lockForUpdate()->first() ?? new DayClose;

            if ($day->exists && $day->isClosed()) {
                throw new CashActionRefused("{$date} is already closed.", 'conflict');
            }

            $day->forceFill([
                'location_id' => $locationId,
                'business_date' => $date,
                'status' => 'closed',
                'closed_by' => $by->id,
                'closed_at' => now(),
                'warnings' => $check['warnings'] ?: null,
                'report' => $report,
            ])->save();

            AuditLog::record('day.close', $day, "Closed the business day {$date}: sales ".money($report['totals']['sales'])->format().', collected '.money($report['totals']['net_collected'])->format().($check['warnings'] ? ' (with '.count($check['warnings']).' warning(s))' : ''), null, ['warnings' => $check['warnings']], $by);

            return $day;
        });

        // The day's report summary, then the owner's email once every location has closed.
        $this->summaries->build($locationId, $date);
        if ($this->owner->allClosed($date)) {
            SendDailyReport::dispatch($date);
        }

        return $day;
    }
}
