<?php

namespace App\Services\GoLive;

use App\Models\Supplier;
use App\Services\Purchasing\SupplierBillService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * What the business owes each supplier at go-live, as an unpaid "opening balance" supplier bill
 * (supplier invoice number OPENING-<ref>). Pay it later like any bill. Suppliers must exist.
 */
class SupplierBalanceImporter extends Importer
{
    public function __construct(private SupplierBillService $bills) {}

    public function kind(): string
    {
        return 'supplier_balances';
    }

    public function required(): array
    {
        return ['supplier', 'amount'];
    }

    public function optional(): array
    {
        return ['as_of', 'due_date', 'reference', 'note'];
    }

    protected function check(array $rows, ImportContext $context): array
    {
        $suppliers = Supplier::query()->get(['id', 'name'])->keyBy(fn ($s) => SupplierImporter::keyFor($s->name));
        $out = [];

        foreach ($rows as ['line' => $line, 'row' => $raw]) {
            $supplier = $suppliers->get(SupplierImporter::keyFor($raw['supplier'] ?? ''));
            $reference = Str::upper(trim($raw['reference'] ?? '')) ?: 'GO-LIVE';
            $row = new ImportRow([$line], $raw, $supplier ? "{$supplier->id}|{$reference}" : null);
            $out[] = $row;

            if (! $supplier) {
                $row->fail("No supplier named \"{$raw['supplier']}\": import suppliers first.");
            }

            $amount = $this->money($row, 'amount', required: true);
            if ($amount !== null && $amount <= 0) {
                $row->fail('amount must be more than zero (record credits from suppliers separately).');
            }

            $asOf = $this->date($row, 'as_of') ?? now()->toDateString();
            $due = $this->date($row, 'due_date');

            $row->data = [
                'supplier_id' => $supplier?->id,
                'supplier_invoice_number' => "OPENING-{$reference}",
                'bill_date' => $asOf,
                'due_date' => $due,
                'subtotal' => (int) $amount,
                'total_amount' => (int) $amount,
                'notes' => trim("Opening balance at go-live (as of {$asOf}). ".trim($raw['note'] ?? '')),
            ];
        }

        // Two rows for the same supplier and reference would be one bill twice.
        $seen = [];
        foreach ($out as $row) {
            if ($row->key === null) {
                continue;
            }
            if (isset($seen[$row->key])) {
                $row->fail("Same supplier and reference as line {$seen[$row->key]}: give each balance its own reference.");
            }
            $seen[$row->key] ??= $row->lines[0];
        }

        return $out;
    }

    protected function apply(ImportRow $row, ImportContext $context): Model
    {
        return $this->bills->createBill($row->data, $context->actor);
    }
}
