<?php

namespace App\Services\Purchasing;

use App\Enums\SupplierBillStatus;
use App\Enums\SupplierPaymentMethod;
use App\Models\AuditLog;
use App\Models\GoodsReceipt;
use App\Models\Supplier;
use App\Models\SupplierBill;
use App\Models\SupplierPayment;
use App\Models\User;
use App\Support\Counter;
use App\Support\SyncVersion;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Manages supplier bills (invoices) with linked GRNs and supplier payments allocated across bills.
 */
class SupplierBillService
{
    /**
     * @param  array{
     *     supplier_id: int,
     *     supplier_invoice_number: string,
     *     bill_date?: string|null,
     *     due_date?: string|null,
     *     subtotal: int,
     *     tax_total?: int|null,
     *     total_amount?: int|null,
     *     goods_receipt_ids?: array<int, int>|null,
     *     notes?: string|null,
     * }  $data
     */
    public function createBill(array $data, User $actor): SupplierBill
    {
        $invoiceNumber = trim((string) ($data['supplier_invoice_number'] ?? ''));
        if ($invoiceNumber === '') {
            throw new InvalidArgumentException('Supplier invoice number is required.');
        }

        return DB::transaction(function () use ($data, $invoiceNumber, $actor) {
            $supplier = Supplier::query()->findOrFail((int) $data['supplier_id']);

            $subtotal = max(0, (int) $data['subtotal']);
            $taxTotal = max(0, (int) ($data['tax_total'] ?? 0));
            $totalAmount = isset($data['total_amount']) && (int) $data['total_amount'] > 0
                ? (int) $data['total_amount']
                : ($subtotal + $taxTotal);

            if ($totalAmount <= 0) {
                throw new InvalidArgumentException('Bill total amount must be greater than zero.');
            }

            $billDate = ! empty($data['bill_date'])
                ? Carbon::parse((string) $data['bill_date'])->toDateString()
                : now()->toDateString();

            $dueDate = ! empty($data['due_date'])
                ? Carbon::parse((string) $data['due_date'])->toDateString()
                : Carbon::parse($billDate)->addDays((int) $supplier->payment_terms_days)->toDateString();

            $grnIds = array_values(array_unique(array_filter(array_map('intval', $data['goods_receipt_ids'] ?? []))));
            if (! empty($grnIds)) {
                $validGrnCount = GoodsReceipt::query()
                    ->where('supplier_id', $supplier->id)
                    ->whereIn('id', $grnIds)
                    ->count();

                if ($validGrnCount !== count($grnIds)) {
                    throw new InvalidArgumentException('One or more linked GRNs do not belong to the selected supplier.');
                }
            }

            $seq = Counter::next('supplier_bill', 'company');
            $number = sprintf('BILL-%06d', $seq);

            $bill = SupplierBill::query()->create([
                'number' => $number,
                'supplier_id' => $supplier->id,
                'supplier_invoice_number' => $invoiceNumber,
                'bill_date' => $billDate,
                'due_date' => $dueDate,
                'subtotal' => $subtotal,
                'tax_total' => $taxTotal,
                'total_amount' => $totalAmount,
                'paid_amount' => 0,
                'status' => SupplierBillStatus::Unpaid,
                'notes' => isset($data['notes']) && trim((string) $data['notes']) !== '' ? trim((string) $data['notes']) : null,
                'created_by' => $actor->id,
                'sync_version' => 0,
            ]);

            if (! empty($grnIds)) {
                $bill->goodsReceipts()->sync($grnIds);
            }

            SyncVersion::bump($bill);

            AuditLog::record(
                'supplier_bill.created',
                $bill,
                "Supplier bill {$bill->number} ({$invoiceNumber}) from {$supplier->name}",
                null,
                [
                    'number' => $bill->number,
                    'supplier_invoice_number' => $invoiceNumber,
                    'total_amount' => $totalAmount,
                    'goods_receipt_ids' => $grnIds,
                ],
                $actor,
            );

            return $bill->fresh(['supplier', 'goodsReceipts', 'createdBy']);
        });
    }

    /**
     * Record a supplier payment and allocate it to one or more unpaid/partially-paid supplier bills.
     *
     * @param  array{
     *     supplier_id: int,
     *     paid_at?: string|null,
     *     method: SupplierPaymentMethod|string,
     *     amount?: int|null,
     *     reference?: string|null,
     *     cheque_number?: string|null,
     *     cheque_date?: string|null,
     *     cheque_bank?: string|null,
     *     notes?: string|null,
     *     allocations: array<int, array{
     *         supplier_bill_id: int,
     *         amount: int,
     *     }>,
     * }  $data
     */
    public function recordPayment(array $data, User $actor): SupplierPayment
    {
        if (empty($data['allocations'])) {
            throw new InvalidArgumentException('A supplier payment must be allocated to at least one bill.');
        }

        $method = $data['method'] instanceof SupplierPaymentMethod
            ? $data['method']
            : SupplierPaymentMethod::from((string) $data['method']);

        if ($method === SupplierPaymentMethod::Cheque && empty(trim((string) ($data['cheque_number'] ?? '')))) {
            throw new InvalidArgumentException('Cheque number is required for cheque payments.');
        }

        return DB::transaction(function () use ($data, $method, $actor) {
            $supplier = Supplier::query()->findOrFail((int) $data['supplier_id']);

            $allocationsByBill = [];
            foreach ($data['allocations'] as $alloc) {
                $billId = (int) ($alloc['supplier_bill_id'] ?? 0);
                $allocAmount = (int) ($alloc['amount'] ?? 0);
                if ($billId <= 0 || $allocAmount <= 0) {
                    continue;
                }
                $allocationsByBill[$billId] = ($allocationsByBill[$billId] ?? 0) + $allocAmount;
            }

            if (empty($allocationsByBill)) {
                throw new InvalidArgumentException('At least one positive bill allocation is required.');
            }

            $billIds = array_keys($allocationsByBill);
            sort($billIds);

            $bills = SupplierBill::query()
                ->whereIn('id', $billIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $totalAllocated = 0;

            foreach ($allocationsByBill as $billId => $allocAmount) {
                /** @var SupplierBill|null $bill */
                $bill = $bills->get($billId);
                if (! $bill || $bill->supplier_id !== $supplier->id) {
                    throw new InvalidArgumentException("Supplier bill [{$billId}] not found for {$supplier->name}.");
                }

                $balanceDue = $bill->balanceDue();
                if ($allocAmount > $balanceDue) {
                    throw new InvalidArgumentException("Allocation exceeds balance due on bill [{$bill->number}].");
                }

                $totalAllocated += $allocAmount;
            }

            $paymentAmount = isset($data['amount']) && (int) $data['amount'] > 0
                ? (int) $data['amount']
                : $totalAllocated;

            if ($paymentAmount !== $totalAllocated) {
                throw new InvalidArgumentException('Payment amount must equal the sum of bill allocations.');
            }

            $seq = Counter::next('supplier_payment', 'company');
            $number = sprintf('SPAY-%06d', $seq);

            $paymentDate = ! empty($data['paid_at']) ? $data['paid_at'] : now()->toDateString();

            $payment = SupplierPayment::query()->create([
                'number' => $number,
                'supplier_id' => $supplier->id,
                'payment_date' => $paymentDate,
                'paid_at' => $paymentDate,
                'method' => $method,
                'amount' => $paymentAmount,
                'reference' => isset($data['reference']) && trim((string) $data['reference']) !== '' ? trim((string) $data['reference']) : null,
                'cheque_number' => isset($data['cheque_number']) && trim((string) $data['cheque_number']) !== '' ? trim((string) $data['cheque_number']) : null,
                'cheque_date' => ! empty($data['cheque_date']) ? $data['cheque_date'] : null,
                'cheque_bank' => isset($data['cheque_bank']) && trim((string) $data['cheque_bank']) !== '' ? trim((string) $data['cheque_bank']) : null,
                'notes' => isset($data['notes']) && trim((string) $data['notes']) !== '' ? trim((string) $data['notes']) : null,
                'created_by' => $actor->id,
                'sync_version' => 0,
            ]);

            foreach ($allocationsByBill as $billId => $allocAmount) {
                /** @var SupplierBill $bill */
                $bill = $bills->get($billId);

                $payment->allocations()->create([
                    'supplier_bill_id' => $bill->id,
                    'amount' => $allocAmount,
                ]);

                $bill->paid_amount += $allocAmount;
                $bill->status = $bill->paid_amount >= $bill->total_amount
                    ? SupplierBillStatus::Paid
                    : SupplierBillStatus::PartiallyPaid;
                $bill->save();

                SyncVersion::bump($bill);
            }

            SyncVersion::bump($payment);

            AuditLog::record(
                'supplier_payment.created',
                $payment,
                "Supplier payment {$payment->number} to {$supplier->name}",
                null,
                [
                    'number' => $payment->number,
                    'supplier_id' => $supplier->id,
                    'amount' => $paymentAmount,
                    'method' => $method->value,
                    'allocations' => $allocationsByBill,
                ],
                $actor,
            );

            return $payment->fresh(['supplier', 'bills', 'allocations.bill', 'createdBy']);
        });
    }
}
