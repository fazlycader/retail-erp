<?php

namespace App\Services\GoLive;

use App\Models\AuditLog;
use App\Models\Supplier;
use App\Rules\SriLankanPhone;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Suppliers: one per name (case and spaces ignored). A supplier that already exists is skipped
 * and reported, never overwritten.
 */
class SupplierImporter extends Importer
{
    public function kind(): string
    {
        return 'suppliers';
    }

    public function required(): array
    {
        return ['name'];
    }

    public function optional(): array
    {
        return ['contact_person', 'phone', 'secondary_phone', 'email', 'address', 'vat_number', 'payment_terms_days', 'credit_limit', 'bank_details', 'notes'];
    }

    public static function keyFor(string $name): string
    {
        return Str::lower(preg_replace('/\s+/', ' ', trim($name)));
    }

    protected function check(array $rows, ImportContext $context): array
    {
        $existing = Supplier::query()->pluck('name')->mapWithKeys(fn ($n) => [self::keyFor($n) => $n])->all();
        $seen = [];
        $out = [];

        foreach ($rows as ['line' => $line, 'row' => $raw]) {
            $name = trim($raw['name'] ?? '');
            $row = new ImportRow([$line], $raw, $name !== '' ? self::keyFor($name) : null);
            $out[] = $row;

            if ($name === '') {
                $row->fail('name is required.');

                continue;
            }

            if (isset($seen[$row->key])) {
                $row->skip("Same supplier as line {$seen[$row->key]}.");

                continue;
            }
            $seen[$row->key] = $line;

            foreach (['phone', 'secondary_phone'] as $field) {
                if (filled($raw[$field] ?? '') && SriLankanPhone::normalize($raw[$field]) === null) {
                    $row->fail("{$field} \"{$raw[$field]}\" is not a Sri Lankan phone number.");
                }
            }

            if (filled($raw['email'] ?? '') && ! filter_var($raw['email'], FILTER_VALIDATE_EMAIL)) {
                $row->fail("email \"{$raw['email']}\" is not an email address.");
            }

            $terms = trim($raw['payment_terms_days'] ?? '');
            if ($terms !== '' && ! ctype_digit($terms)) {
                $row->fail("payment_terms_days \"{$terms}\" must be a whole number of days.");
            }

            $creditLimit = $this->money($row, 'credit_limit');

            if (isset($existing[$row->key]) && $row->status !== ImportRow::FAIL) {
                $row->skip("Supplier \"{$existing[$row->key]}\" already exists; left unchanged.");
            }

            $row->data = [
                'name' => preg_replace('/\s+/', ' ', $name),
                'contact_person' => $raw['contact_person'] ?? null ?: null,
                'phone' => filled($raw['phone'] ?? '') ? SriLankanPhone::normalize($raw['phone']) : null,
                'secondary_phone' => filled($raw['secondary_phone'] ?? '') ? SriLankanPhone::normalize($raw['secondary_phone']) : null,
                'email' => filled($raw['email'] ?? '') ? Str::lower($raw['email']) : null,
                'address' => $raw['address'] ?? null ?: null,
                'vat_number' => $raw['vat_number'] ?? null ?: null,
                'payment_terms_days' => $terms !== '' && ctype_digit($terms) ? (int) $terms : 30,
                'credit_limit' => max(0, (int) $creditLimit),
                'bank_details' => $raw['bank_details'] ?? null ?: null,
                'notes' => $raw['notes'] ?? null ?: null,
                'is_active' => true,
            ];
        }

        return $out;
    }

    protected function apply(ImportRow $row, ImportContext $context): Model
    {
        $supplier = Supplier::query()->create($row->data);
        AuditLog::record('supplier.imported', $supplier, "Imported supplier {$supplier->name} ({$context->file})", null, null, $context->actor);

        return $supplier;
    }
}
