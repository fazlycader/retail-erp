<?php

namespace App\Http\Controllers\Admin;

use App\Enums\CustomerType;
use App\Enums\SalesOrderStatus;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Rules\SriLankanPhone;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CustomerController extends Controller
{
    private const DISTRICTS = [
        'Colombo', 'Gampaha', 'Kalutara', 'Kandy', 'Matale', 'Nuwara Eliya',
        'Galle', 'Matara', 'Hambantota', 'Jaffna', 'Kilinochchi', 'Mannar',
        'Vavuniya', 'Mullaitivu', 'Batticaloa', 'Ampara', 'Trincomalee',
        'Kurunegala', 'Puttalam', 'Anuradhapura', 'Polonnaruwa', 'Badulla',
        'Moneragala', 'Ratnapura', 'Kegalle',
    ];

    public function index(Request $request): Response
    {
        $user = $request->user();
        $canManage = $user->can('manage-customers');
        $search = trim((string) $request->string('search'));
        $filter = (string) $request->string('filter', 'all');

        $normalizedPhone = SriLankanPhone::normalize($search);
        $digitsOnly = preg_replace('/\D+/', '', $search);

        $customers = Customer::query()
            ->when($search !== '', function ($q) use ($search, $normalizedPhone, $digitsOnly) {
                $q->where(function ($sub) use ($search, $normalizedPhone, $digitsOnly) {
                    $sub->where('name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%")
                        ->orWhere('secondary_phone', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('nic', 'like', "%{$search}%")
                        ->orWhere('vat_number', 'like', "%{$search}%");

                    if ($normalizedPhone) {
                        $sub->orWhere('phone', $normalizedPhone)
                            ->orWhere('secondary_phone', $normalizedPhone);
                    }
                    if ($digitsOnly !== '' && strlen($digitsOnly) >= 4) {
                        $sub->orWhere('phone', 'like', "%{$digitsOnly}%")
                            ->orWhere('secondary_phone', 'like', "%{$digitsOnly}%");
                    }
                });
            })
            ->when($filter === 'individual', fn ($q) => $q->where('type', CustomerType::Individual->value))
            ->when($filter === 'business', fn ($q) => $q->where('type', CustomerType::Business->value))
            ->when($filter === 'blacklisted', fn ($q) => $q->where('is_blacklisted', true))
            ->orderByDesc('last_purchase_at')
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        $balancesByCustomer = [];
        if ($customers->isNotEmpty()) {
            $rows = DB::table('sales_orders')
                ->whereIn('customer_id', $customers->pluck('id')->all())
                ->whereNotIn('status', [SalesOrderStatus::Draft->value, SalesOrderStatus::Cancelled->value])
                ->groupBy('customer_id')
                ->selectRaw('customer_id, SUM(balance_due) as total_balance_due')
                ->get();

            foreach ($rows as $row) {
                $balancesByCustomer[(int) $row->customer_id] = (int) $row->total_balance_due;
            }
        }

        $customers->through(fn (Customer $c) => $this->formatCustomer($c, $balancesByCustomer[$c->id] ?? 0));

        return Inertia::render('Admin/Customers/Index', [
            'customers' => $customers,
            'filters' => [
                'search' => $search,
                'filter' => $filter,
            ],
            'customerTypes' => CustomerType::options(),
            'districts' => self::DISTRICTS,
            'canManage' => $canManage,
        ]);
    }

    public function show(Request $request, Customer $customer): Response
    {
        $user = $request->user();
        $canManage = $user->can('manage-customers');

        $salesOrders = $customer->salesOrders()
            ->with(['location:id,name,code', 'salesperson:id,name'])
            ->withCount('lines')
            ->latest('id')
            ->limit(50)
            ->get();

        $quotations = $customer->quotations()
            ->with(['location:id,name,code', 'salesperson:id,name'])
            ->withCount('lines')
            ->latest('id')
            ->limit(30)
            ->get();

        $openOrders = $salesOrders->filter(fn ($o) => ! in_array($o->status, [
            SalesOrderStatus::Completed,
            SalesOrderStatus::Fulfilled,
            SalesOrderStatus::Cancelled,
        ], true))->values();

        $balanceDue = (int) $customer->salesOrders()
            ->whereNotIn('status', [SalesOrderStatus::Draft->value, SalesOrderStatus::Cancelled->value])
            ->sum('balance_due');

        return Inertia::render('Admin/Customers/Show', [
            'customer' => $this->formatCustomer($customer, $balanceDue),
            'salesOrders' => $salesOrders->map(fn ($o) => [
                'id' => $o->id,
                'number' => $o->number,
                'invoice_number' => $o->invoice_number,
                'location_name' => $o->location?->name,
                'location_code' => $o->location?->code,
                'salesperson_name' => $o->salesperson?->name,
                'status' => $o->status->value,
                'status_label' => $o->status->label(),
                'payment_status' => $o->payment_status->value,
                'payment_status_label' => $o->payment_status->label(),
                'lines_count' => $o->lines_count,
                'grand_total' => $o->grand_total,
                'paid_amount' => $o->paid_amount,
                'balance_due' => $o->balance_due,
                'business_date' => $o->business_date?->toDateString(),
                'created_at' => $o->created_at?->toIso8601String(),
            ])->values()->all(),
            'openOrdersCount' => $openOrders->count(),
            'quotations' => $quotations->map(fn ($q) => [
                'id' => $q->id,
                'number' => $q->number,
                'location_name' => $q->location?->name,
                'salesperson_name' => $q->salesperson?->name,
                'status' => $q->status->value,
                'status_label' => $q->status->label(),
                'valid_until' => $q->valid_until?->toDateString(),
                'follow_up_date' => $q->follow_up_date?->toDateString(),
                'grand_total' => $q->grand_total,
                'created_at' => $q->created_at?->toIso8601String(),
            ])->values()->all(),
            'customerTypes' => CustomerType::options(),
            'districts' => self::DISTRICTS,
            'canManage' => $canManage,
            'canOverride' => $user->can('override'),
        ]);
    }

    public function store(Request $request): RedirectResponse|JsonResponse
    {
        Gate::authorize('manage-customers');

        $validated = $this->validateCustomer($request);

        $customer = DB::transaction(function () use ($validated, $request) {
            $creditLimit = isset($validated['credit_limit']) && trim((string) $validated['credit_limit']) !== ''
                ? Money::fromDecimal((string) $validated['credit_limit'])->cents
                : 0;

            $customer = Customer::query()->create([
                'name' => trim($validated['name']),
                'phone' => $validated['phone'],
                'secondary_phone' => $validated['secondary_phone'] ?? null,
                'email' => $this->nullableTrim($validated['email'] ?? null),
                'nic' => $this->nullableTrim($validated['nic'] ?? null),
                'type' => $validated['type'] ?? CustomerType::Individual->value,
                'vat_number' => $this->nullableTrim($validated['vat_number'] ?? null),
                'addresses' => $this->sanitizeAddresses($validated['addresses'] ?? []),
                'notes' => $this->nullableTrim($validated['notes'] ?? null),
                'sms_opt_out' => (bool) ($validated['sms_opt_out'] ?? false),
                'credit_limit' => $creditLimit,
                'is_blacklisted' => false,
            ]);

            AuditLog::record(
                'customer.created',
                $customer,
                "Created customer {$customer->name} ({$customer->phone})",
                null,
                ['name' => $customer->name, 'phone' => $customer->phone],
                $request->user(),
            );

            return $customer;
        });

        if ($request->wantsJson()) {
            return response()->json([
                'customer' => $this->formatCustomer($customer, 0),
            ], 201);
        }

        return back()->with('success', "Customer {$customer->name} created.");
    }

    public function update(Request $request, Customer $customer): RedirectResponse
    {
        Gate::authorize('manage-customers');

        $validated = $this->validateCustomer($request, $customer);

        DB::transaction(function () use ($validated, $customer, $request) {
            $creditLimit = isset($validated['credit_limit']) && trim((string) $validated['credit_limit']) !== ''
                ? Money::fromDecimal((string) $validated['credit_limit'])->cents
                : 0;

            $isBlacklisted = (bool) ($validated['is_blacklisted'] ?? $customer->is_blacklisted);
            $blacklistReason = $isBlacklisted
                ? $this->nullableTrim($validated['blacklist_reason'] ?? $customer->blacklist_reason)
                : null;

            $customer->update([
                'name' => trim($validated['name']),
                'phone' => $validated['phone'],
                'secondary_phone' => $validated['secondary_phone'] ?? null,
                'email' => $this->nullableTrim($validated['email'] ?? null),
                'nic' => $this->nullableTrim($validated['nic'] ?? null),
                'type' => $validated['type'] ?? $customer->type->value,
                'vat_number' => $this->nullableTrim($validated['vat_number'] ?? null),
                'addresses' => array_key_exists('addresses', $validated)
                    ? $this->sanitizeAddresses($validated['addresses'] ?? [])
                    : $customer->addresses,
                'notes' => $this->nullableTrim($validated['notes'] ?? null),
                'sms_opt_out' => (bool) ($validated['sms_opt_out'] ?? $customer->sms_opt_out),
                'credit_limit' => $creditLimit,
                'is_blacklisted' => $isBlacklisted,
                'blacklist_reason' => $blacklistReason,
            ]);

            AuditLog::record(
                'customer.updated',
                $customer,
                "Updated customer {$customer->name}",
                null,
                [
                    'name' => $customer->name,
                    'phone' => $customer->phone,
                    'is_blacklisted' => $customer->is_blacklisted,
                ],
                $request->user(),
            );
        });

        return back()->with('success', "Customer {$customer->name} updated.");
    }

    /**
     * Fast customer lookup by phone (or name/NIC) used across Quotations, Sales Orders, POS, etc.
     */
    public function lookup(Request $request): JsonResponse
    {
        $q = trim((string) $request->string('q'));
        if ($q === '') {
            return response()->json(['customers' => []]);
        }

        $normalized = SriLankanPhone::normalize($q);
        $digits = preg_replace('/\D+/', '', $q);

        $customers = Customer::query()
            ->where(function ($sub) use ($q, $normalized, $digits) {
                $sub->where('phone', 'like', "%{$q}%")
                    ->orWhere('secondary_phone', 'like', "%{$q}%")
                    ->orWhere('name', 'like', "%{$q}%")
                    ->orWhere('nic', 'like', "%{$q}%");

                if ($normalized) {
                    $sub->orWhere('phone', $normalized)
                        ->orWhere('secondary_phone', $normalized);
                }
                if ($digits !== '' && strlen($digits) >= 4) {
                    $sub->orWhere('phone', 'like', "%{$digits}%")
                        ->orWhere('secondary_phone', 'like', "%{$digits}%");
                }
            })
            ->orderByDesc('last_purchase_at')
            ->orderBy('name')
            ->limit(15)
            ->get();

        return response()->json([
            'customers' => $customers->map(fn (Customer $c) => $this->formatCustomer($c, 0))->values()->all(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateCustomer(Request $request, ?Customer $customer = null): array
    {
        $normalizedPhone = SriLankanPhone::normalize((string) $request->input('phone', ''));
        if ($normalizedPhone) {
            $request->merge(['phone' => $normalizedPhone]);
        }

        $rawSecondary = trim((string) $request->input('secondary_phone', ''));
        if ($rawSecondary !== '') {
            $normalizedSecondary = SriLankanPhone::normalize($rawSecondary);
            if ($normalizedSecondary) {
                $request->merge(['secondary_phone' => $normalizedSecondary]);
            }
        } else {
            $request->merge(['secondary_phone' => null]);
        }

        return $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'phone' => [
                'required',
                'string',
                new SriLankanPhone,
                Rule::unique('customers', 'phone')->ignore($customer?->id),
            ],
            'secondary_phone' => ['nullable', 'string', new SriLankanPhone],
            'email' => ['nullable', 'email', 'max:160'],
            'nic' => ['nullable', 'string', 'max:20', 'regex:/^(\d{9}[VvXx]|\d{12})$/'],
            'type' => ['nullable', Rule::enum(CustomerType::class)],
            'vat_number' => ['nullable', 'string', 'max:60'],
            'addresses' => ['nullable', 'array', 'max:5'],
            'addresses.*.label' => ['nullable', 'string', 'max:60'],
            'addresses.*.line1' => ['required_with:addresses', 'string', 'max:255'],
            'addresses.*.line2' => ['nullable', 'string', 'max:255'],
            'addresses.*.city' => ['nullable', 'string', 'max:100'],
            'addresses.*.district' => ['nullable', 'string', 'max:80'],
            'addresses.*.maps_url' => ['nullable', 'url', 'max:500'],
            'addresses.*.is_default' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'sms_opt_out' => ['nullable', 'boolean'],
            'credit_limit' => ['nullable', 'string', 'max:40'],
            'is_blacklisted' => ['nullable', 'boolean'],
            'blacklist_reason' => ['nullable', 'string', 'max:255'],
        ], [
            'nic.regex' => 'Enter a valid Sri Lankan NIC (e.g. 901234567V or 199012345678).',
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $addresses
     * @return array<int, array<string, mixed>>
     */
    private function sanitizeAddresses(array $addresses): array
    {
        $cleaned = [];
        foreach (array_slice($addresses, 0, 5) as $addr) {
            $line1 = trim((string) ($addr['line1'] ?? ''));
            if ($line1 === '') {
                continue;
            }
            $cleaned[] = [
                'label' => $this->nullableTrim($addr['label'] ?? null) ?? 'Address',
                'line1' => $line1,
                'line2' => $this->nullableTrim($addr['line2'] ?? null),
                'city' => $this->nullableTrim($addr['city'] ?? null),
                'district' => $this->nullableTrim($addr['district'] ?? null),
                'maps_url' => $this->nullableTrim($addr['maps_url'] ?? null),
                'is_default' => (bool) ($addr['is_default'] ?? false),
            ];
        }

        if ($cleaned !== [] && ! collect($cleaned)->contains('is_default', true)) {
            $cleaned[0]['is_default'] = true;
        }

        return $cleaned;
    }

    /**
     * @return array<string, mixed>
     */
    private function formatCustomer(Customer $customer, int $balanceDue = 0): array
    {
        return [
            'id' => $customer->id,
            'name' => $customer->name,
            'phone' => $customer->phone,
            'phone_display' => $customer->displayPhone(),
            'secondary_phone' => $customer->secondary_phone,
            'secondary_phone_display' => SriLankanPhone::display($customer->secondary_phone),
            'email' => $customer->email,
            'nic' => $customer->nic,
            'type' => $customer->type->value,
            'type_label' => $customer->type->label(),
            'vat_number' => $customer->vat_number,
            'addresses' => $customer->addresses ?? [],
            'default_address' => $customer->defaultAddress(),
            'notes' => $customer->notes,
            'sms_opt_out' => $customer->sms_opt_out,
            'credit_limit' => $customer->credit_limit,
            'credit_limit_decimal' => Money::fromCents($customer->credit_limit)->toDecimal(),
            'is_blacklisted' => $customer->is_blacklisted,
            'blacklist_reason' => $customer->blacklist_reason,
            'first_purchase_at' => $customer->first_purchase_at?->toIso8601String(),
            'last_purchase_at' => $customer->last_purchase_at?->toIso8601String(),
            'total_orders_count' => $customer->total_orders_count,
            'total_spent' => $customer->total_spent,
            'balance_due' => $balanceDue,
        ];
    }

    private function nullableTrim(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
