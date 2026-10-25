<?php

namespace App\Services\GoLive;

use App\Enums\CustomerType;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Rules\SriLankanPhone;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Customers, de-duplicated by phone (normalised to +94…): a phone already in the system or earlier
 * in the file is skipped and reported (with both names when they differ), never merged by guesswork.
 */
class CustomerImporter extends Importer
{
    public function kind(): string
    {
        return 'customers';
    }

    public function required(): array
    {
        return ['name', 'phone'];
    }

    public function optional(): array
    {
        return ['secondary_phone', 'email', 'nic', 'type', 'vat_number', 'address', 'city', 'district', 'credit_limit', 'notes'];
    }

    protected function check(array $rows, ImportContext $context): array
    {
        $phones = array_values(array_filter(array_map(fn ($r) => SriLankanPhone::normalize($r['row']['phone'] ?? ''), $rows)));
        $existing = Customer::query()->whereIn('phone', $phones)->pluck('name', 'phone')->all();
        $seen = [];
        $out = [];

        foreach ($rows as ['line' => $line, 'row' => $raw]) {
            $name = trim(preg_replace('/\s+/', ' ', $raw['name'] ?? ''));
            $phone = SriLankanPhone::normalize($raw['phone'] ?? '');
            $row = new ImportRow([$line], $raw, $phone);
            $out[] = $row;

            if ($name === '') {
                $row->fail('name is required.');
            }

            if ($phone === null) {
                $row->fail(blank($raw['phone'] ?? '') ? 'phone is required (customers are matched by phone).' : "phone \"{$raw['phone']}\" is not a Sri Lankan phone number.");

                continue;
            }

            if (isset($seen[$phone])) {
                [$firstLine, $firstName] = $seen[$phone];
                $row->skip("Same phone as line {$firstLine}".(Str::lower($firstName) !== Str::lower($name) ? " (\"{$firstName}\" there, \"{$name}\" here): check which is right" : '').'.');

                continue;
            }
            $seen[$phone] = [$line, $name];

            $secondary = null;
            if (filled($raw['secondary_phone'] ?? '')) {
                $secondary = SriLankanPhone::normalize($raw['secondary_phone']);
                if ($secondary === null) {
                    $row->fail("secondary_phone \"{$raw['secondary_phone']}\" is not a Sri Lankan phone number.");
                }
            }

            if (filled($raw['email'] ?? '') && ! filter_var($raw['email'], FILTER_VALIDATE_EMAIL)) {
                $row->fail("email \"{$raw['email']}\" is not an email address.");
            }

            $type = Str::lower(trim($raw['type'] ?? '')) ?: 'individual';
            if (! CustomerType::tryFrom($type)) {
                $row->fail("type \"{$raw['type']}\" must be individual or business.");
            }

            $creditLimit = $this->money($row, 'credit_limit');

            if (isset($existing[$phone]) && $row->status !== ImportRow::FAIL) {
                $row->skip("Already a customer with this phone: \"{$existing[$phone]}\""
                    .(Str::lower($existing[$phone]) !== Str::lower($name) ? " (file says \"{$name}\")" : '').'; left unchanged.');
            }

            $address = array_filter(['line1' => trim($raw['address'] ?? ''), 'city' => trim($raw['city'] ?? ''), 'district' => trim($raw['district'] ?? '')]);

            $row->data = [
                'name' => $name,
                'phone' => $phone,
                'secondary_phone' => $secondary,
                'email' => filled($raw['email'] ?? '') ? Str::lower(trim($raw['email'])) : null,
                'nic' => filled($raw['nic'] ?? '') ? strtoupper(trim($raw['nic'])) : null,
                'type' => CustomerType::tryFrom($type) ?? CustomerType::Individual,
                'vat_number' => trim($raw['vat_number'] ?? '') ?: null,
                'addresses' => isset($address['line1']) ? [['label' => 'Main', 'line1' => $address['line1'], 'city' => $address['city'] ?? null, 'district' => $address['district'] ?? null, 'is_default' => true]] : null,
                'credit_limit' => max(0, (int) $creditLimit),
                'notes' => trim($raw['notes'] ?? '') ?: null,
            ];
        }

        return $out;
    }

    protected function apply(ImportRow $row, ImportContext $context): Model
    {
        $customer = Customer::query()->create($row->data);
        AuditLog::record('customer.imported', $customer, "Imported customer {$customer->name} ({$context->file})", null, null, $context->actor);

        return $customer;
    }
}
