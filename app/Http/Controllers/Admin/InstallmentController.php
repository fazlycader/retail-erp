<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Role;
use App\Exceptions\ApprovalRefused;
use App\Exceptions\CashActionRefused;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\InstallmentAgreement;
use App\Models\InstallmentPlan;
use App\Models\InstallmentSchedule;
use App\Models\PaymentMethod;
use App\Models\SalesOrder;
use App\Services\Installments\InstallmentService;
use App\Support\BasisPoints;
use App\Support\ManagerApproval;
use App\Support\Money;
use App\Support\SalesDocuments;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class InstallmentController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $filter = (string) $request->string('status', 'active');
        $agreements = InstallmentAgreement::query()
            ->visibleTo($user)
            ->with(['customer:id,name,phone,nic', 'location:id,name,code', 'plan:id,name', 'salesOrder:id,number,invoice_number', 'schedule'])
            ->withCount('schedule')
            ->when($filter !== 'all', fn ($query) => $query->where('status', $filter))
            ->latest('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (InstallmentAgreement $agreement) => $this->agreementSummary($agreement));

        $scopedSchedule = InstallmentSchedule::query()
            ->whereHas('agreement', fn ($query) => $query->visibleTo($user)->where('status', 'active'))
            ->whereRaw('(amount_due > amount_paid OR late_fee_charged > late_fee_paid)');
        $overdueQuery = (clone $scopedSchedule)->whereDate('due_date', '<', today());

        $dueToday = (clone $scopedSchedule)
            ->whereDate('due_date', today())
            ->with('agreement.customer')
            ->orderBy('due_date')
            ->get()
            ->map(fn (InstallmentSchedule $line) => $this->scheduleSummary($line));
        $arrears = [
            'days_1_7' => (clone $overdueQuery)->whereDate('due_date', '>=', today()->subDays(7))->count(),
            'days_8_30' => (clone $overdueQuery)->whereDate('due_date', '<', today()->subDays(7))->whereDate('due_date', '>=', today()->subDays(30))->count(),
            'days_31_plus' => (clone $overdueQuery)->whereDate('due_date', '<', today()->subDays(30))->count(),
        ];
        $overdue = (clone $overdueQuery)
            ->with('agreement.customer')
            ->orderBy('due_date')
            ->paginate(50, ['*'], 'arrears_page')
            ->withQueryString()
            ->through(fn (InstallmentSchedule $line) => $this->scheduleSummary($line) + [
                'days_overdue' => $line->due_date->diffInDays(today()),
            ]);

        return Inertia::render('Admin/Installments/Index', [
            'agreements' => $agreements,
            'filter' => $filter,
            'dueToday' => $dueToday,
            'overdue' => $overdue,
            'arrears' => $arrears,
            'canManage' => $user->role->canOverride(),
        ]);
    }

    public function create(Request $request): Response
    {
        $orderId = $request->integer('order_id');
        $order = SalesOrder::query()->visibleTo($request->user())
            ->with(['customer:id,name,phone,nic,credit_limit,is_blacklisted', 'location:id,name,code'])
            ->findOrFail($orderId);
        abort_unless($request->user()->worksAt($order->location_id), 403);
        abort_if($order->installmentAgreement()->exists(), 409, 'This order already has an installment agreement.');

        $plans = InstallmentPlan::query()->where('is_active', true)->orderBy('name')->get();
        $methods = PaymentMethod::query()->active()->orderBy('sort')->get();

        return Inertia::render('Admin/Installments/Create', [
            'order' => [
                'id' => $order->id,
                'number' => $order->number,
                'invoice_number' => $order->invoice_number,
                'customer' => $order->customer ? [
                    'id' => $order->customer->id,
                    'name' => $order->customer->name,
                    'nic' => $order->customer->nic,
                    'credit_limit' => $order->customer->credit_limit,
                    'is_blacklisted' => $order->customer->is_blacklisted,
                ] : null,
                'location' => $order->location?->only(['id', 'name', 'code']),
                'grand_total' => $order->grand_total,
                'balance_due' => $order->balance_due,
            ],
            'plans' => $plans->map(fn (InstallmentPlan $plan) => [
                'id' => $plan->id,
                'name' => $plan->name,
                'terms' => array_map('intval', $plan->terms ?? []),
                'minimum_down_payment_bp' => $plan->minimum_down_payment_bp,
                'charge_method' => $plan->charge_method,
                'flat_bp' => $plan->flat_bp,
                'document_fee_cents' => $plan->document_fee_cents,
                'late_fee_method' => $plan->late_fee_method,
            ]),
            'paymentMethods' => $methods->map(fn (PaymentMethod $method) => [
                'id' => $method->id,
                'name' => $method->name,
                'code' => $method->code->value,
                'requires_reference' => $method->requires_reference,
            ]),
            'approvers' => ManagerApproval::approvers($order->location_id),
            'settingsReady' => filled(CompanySetting::current()->installment_agreement_terms)
                && in_array(CompanySetting::current()->installment_tax_treatment, ['non_taxable', 'vat_inclusive', 'vat_exclusive'], true)
                && in_array(CompanySetting::current()->installment_early_settlement_method, ['none', 'charge_rebate'], true)
                && CompanySetting::current()->installment_ownership_until_paid !== null
                && (! in_array(CompanySetting::current()->installment_tax_treatment, ['vat_inclusive', 'vat_exclusive'], true) || CompanySetting::current()->vat_registered),
            'today' => today()->toDateString(),
        ]);
    }

    public function preview(Request $request, InstallmentService $service)
    {
        $data = $request->validate([
            'order_id' => ['required', 'integer', 'exists:sales_orders,id'],
            'plan_id' => ['required', 'integer', 'exists:installment_plans,id'],
            'term_months' => ['required', 'integer', 'min:1', 'max:120'],
            'down_payment_cents' => ['required', 'integer', 'min:0'],
            'start_date' => ['required', 'date'],
        ]);
        $order = SalesOrder::query()->visibleTo($request->user())->findOrFail($data['order_id']);
        abort_unless($request->user()->worksAt($order->location_id), 403);

        try {
            return response()->json($service->preview(
                $order,
                InstallmentPlan::query()->findOrFail($data['plan_id']),
                (int) $data['term_months'],
                (int) $data['down_payment_cents'],
                $data['start_date'],
            ));
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['preview' => $exception->getMessage()]);
        }

    }

    public function draftPdf(Request $request, InstallmentService $service): HttpResponse
    {
        $data = $request->validate([
            'order_id' => ['required', 'integer', 'exists:sales_orders,id'],
            'plan_id' => ['required', 'integer', 'exists:installment_plans,id'],
            'term_months' => ['required', 'integer', 'min:1', 'max:120'],
            'down_payment_cents' => ['required', 'integer', 'min:0'],
            'start_date' => ['required', 'date'],
        ]);
        $order = SalesOrder::query()->visibleTo($request->user())->with(['customer', 'location'])->findOrFail($data['order_id']);
        abort_unless($request->user()->worksAt($order->location_id), 403);
        if (! $order->customer || blank($order->customer->nic) || ! $order->invoice_number || in_array($order->status->value, ['draft', 'cancelled'], true)) {
            throw ValidationException::withMessages(['order_id' => 'A confirmed sale with a customer NIC is required for the agreement draft.']);
        }
        if ($order->installmentAgreement()->exists()) {
            throw ValidationException::withMessages(['order_id' => 'This sales order already has an installment agreement.']);
        }
        $plan = InstallmentPlan::query()->findOrFail($data['plan_id']);
        try {
            $preview = $service->preview(
                $order,
                $plan,
                (int) $data['term_months'],
                (int) $data['down_payment_cents'],
                $data['start_date'],
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['preview' => $exception->getMessage()]);
        }

        $settings = CompanySetting::current();
        $agreement = new InstallmentAgreement([
            'number' => 'DRAFT',
            'start_date' => $data['start_date'],
            'financed_amount' => $preview['financed_amount'],
            'down_payment' => $preview['down_payment'],
            'charges' => $preview['total_payable'] - $preview['financed_amount'],
            'tax_cents' => $preview['tax_cents'],
            'tax_rate_bp_snapshot' => $preview['tax_rate_bp'],
            'total_payable' => $preview['total_payable'],
            'term_months' => (int) $data['term_months'],
            'tax_treatment_snapshot' => $preview['tax_treatment'],
            'ownership_until_paid_snapshot' => $settings->installment_ownership_until_paid,
            'agreement_terms_snapshot' => $settings->installment_agreement_terms,
        ]);
        $agreement->setRelation('customer', $order->customer);
        $agreement->setRelation('salesOrder', $order);
        $agreement->setRelation('location', $order->location);
        $agreement->setRelation('plan', $plan);
        $agreement->setRelation('guarantors', collect());
        $agreement->setRelation('schedule', collect($preview['schedule'])->map(
            fn (array $row) => new InstallmentSchedule($row),
        ));
        $pdf = SalesDocuments::renderPdf('documents.installment-agreement', ['agreement' => $agreement, 'company' => $settings]);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="installment-agreement-draft.pdf"',
        ]);
    }

    public function store(Request $request, InstallmentService $service): RedirectResponse
    {
        $validated = $request->validate([
            'sales_order_id' => ['required', 'integer', 'exists:sales_orders,id'],
            'plan_id' => ['required', 'integer', 'exists:installment_plans,id'],
            'term_months' => ['required', 'integer', 'min:1', 'max:120'],
            'down_payment_cents' => ['required', 'integer', 'min:0'],
            'start_date' => ['required', 'date'],
            'payment_method_id' => [Rule::requiredIf((int) $request->input('down_payment_cents') > 0), 'nullable', 'integer', 'exists:payment_methods,id'],
            'tendered_cents' => ['nullable', 'integer', 'min:0'],
            'reference' => ['nullable', 'string', 'max:100'],
            'cheque_bank' => ['nullable', 'string', 'max:80'],
            'cheque_branch' => ['nullable', 'string', 'max:80'],
            'cheque_number' => ['nullable', 'string', 'max:30'],
            'cheque_date' => ['nullable', 'date'],
            'approver_id' => ['nullable', 'integer', 'exists:users,id'],
            'pin' => ['nullable', 'string', 'max:10'],
            'signed_agreement' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
            'guarantors' => ['nullable', 'array', 'max:5'],
            'guarantors.*.name' => ['required', 'string', 'max:160'],
            'guarantors.*.nic' => ['required', 'string', 'max:20'],
            'guarantors.*.phone' => ['required', 'string', 'max:20'],
            'guarantors.*.address' => ['required', 'string', 'max:1000'],
        ]);
        $order = SalesOrder::query()->visibleTo($request->user())->findOrFail($validated['sales_order_id']);
        abort_unless($request->user()->worksAt($order->location_id), 403);
        $method = isset($validated['payment_method_id'])
            ? PaymentMethod::query()->findOrFail($validated['payment_method_id'])
            : null;
        $path = $request->file('signed_agreement')->store('installment-agreements', 'local');

        try {
            $agreement = $service->create([
                'sales_order_id' => (int) $validated['sales_order_id'],
                'plan_id' => (int) $validated['plan_id'],
                'term_months' => (int) $validated['term_months'],
                'down_payment' => (int) $validated['down_payment_cents'],
                'start_date' => $validated['start_date'],
                'signed_agreement_path' => $path,
                'payment_method_id' => $validated['payment_method_id'] ?? null,
                'tendered' => $validated['tendered_cents'] ?? null,
                'reference' => $validated['reference'] ?? null,
                'cheque_bank' => $validated['cheque_bank'] ?? null,
                'cheque_branch' => $validated['cheque_branch'] ?? null,
                'cheque_number' => $validated['cheque_number'] ?? null,
                'cheque_date' => $validated['cheque_date'] ?? null,
                'approver_id' => $validated['approver_id'] ?? null,
                'pin' => $validated['pin'] ?? null,
                'guarantors' => $validated['guarantors'] ?? [],
            ], $request->user());
        } catch (ApprovalRefused $exception) {
            Storage::disk('local')->delete($path);
            throw ValidationException::withMessages(['pin' => $exception->getMessage()]);
        } catch (CashActionRefused $exception) {
            Storage::disk('local')->delete($path);
            throw ValidationException::withMessages(['payment' => $exception->getMessage()]);
        } catch (InvalidArgumentException $exception) {
            Storage::disk('local')->delete($path);
            throw ValidationException::withMessages(['installment' => $exception->getMessage()]);
        }

        return to_route('installments.show', $agreement)->with('success', "Agreement {$agreement->number} created.");
    }

    public function show(Request $request, InstallmentAgreement $agreement, InstallmentService $service): Response
    {
        $agreement = InstallmentAgreement::query()->visibleTo($request->user())
            ->with(['customer', 'location', 'salesOrder', 'plan', 'schedule', 'guarantors', 'contactLogs.user', 'payments.method', 'payments.allocations.schedule'])
            ->findOrFail($agreement->id);
        $quote = $service->settlementQuote($agreement);

        return Inertia::render('Admin/Installments/Show', [
            'agreement' => $this->agreementSummary($agreement) + [
                'terms' => $agreement->agreement_terms_snapshot,
                'tax_treatment' => $agreement->tax_treatment_snapshot,
                'tax_cents' => $agreement->tax_cents,
                'ownership_until_paid' => $agreement->ownership_until_paid_snapshot,
                'signed_agreement_available' => (bool) $agreement->signed_agreement_path,
                'schedule' => $agreement->schedule->map(function (InstallmentSchedule $line) use ($agreement) {
                    $line->setRelation('agreement', $agreement);

                    return $this->scheduleSummary($line);
                }),
                'guarantors' => $agreement->guarantors->map->only(['name', 'nic', 'phone', 'address']),
                'payments' => $agreement->payments->map(fn ($payment) => [
                    'id' => $payment->id,
                    'number' => $payment->number,
                    'date' => $payment->business_date?->toDateString(),
                    'amount' => $payment->amount,
                    'method' => $payment->method?->name,
                    'reference' => $payment->reference,
                    'status' => $payment->status->value,
                    'allocations' => $payment->allocations->map(fn ($allocation) => [
                        'kind' => $allocation->kind,
                        'amount' => $allocation->amount,
                        'due_date' => $allocation->schedule?->due_date?->toDateString(),
                    ]),
                ]),
                'contactLogs' => $agreement->contactLogs->map(fn ($log) => [
                    'notes' => $log->notes,
                    'promise_to_pay_on' => $log->promise_to_pay_on?->toDateString(),
                    'user' => $log->user?->name,
                    'created_at' => $log->created_at?->format('Y-m-d H:i'),
                ]),
                'settlement' => $quote,
            ],
            'paymentMethods' => PaymentMethod::query()->active()->get()->map(fn (PaymentMethod $method) => [
                'id' => $method->id,
                'name' => $method->name,
                'code' => $method->code->value,
                'requires_reference' => $method->requires_reference,
            ]),
            'canManage' => $request->user()->role->canOverride(),
        ]);
    }

    public function collect(Request $request, InstallmentAgreement $agreement, InstallmentService $service): RedirectResponse
    {
        $data = $this->validatePayment($request);
        $this->assertAccessible($request, $agreement);

        try {
            $service->collect($agreement, $data, $request->user());
        } catch (ApprovalRefused $exception) {
            throw ValidationException::withMessages(['pin' => $exception->getMessage()]);
        } catch (CashActionRefused $exception) {
            throw ValidationException::withMessages(['payment' => $exception->getMessage()]);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['payment' => $exception->getMessage()]);
        }

        return back()->with('success', 'Installment payment recorded and allocated.');
    }

    public function settleEarly(Request $request, InstallmentAgreement $agreement, InstallmentService $service): RedirectResponse
    {
        $data = $this->validatePayment($request);
        $this->assertAccessible($request, $agreement);

        try {
            $service->settleEarly($agreement, $data, $request->user());
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['payment' => $exception->getMessage()]);
        } catch (CashActionRefused $exception) {
            throw ValidationException::withMessages(['payment' => $exception->getMessage()]);
        }

        return back()->with('success', 'Early settlement recorded.');
    }

    public function contact(Request $request, InstallmentAgreement $agreement, InstallmentService $service): RedirectResponse
    {
        $this->assertAccessible($request, $agreement);
        $data = $request->validate([
            'schedule_id' => ['nullable', 'integer', Rule::exists('installment_schedule', 'id')->where('installment_agreement_id', $agreement->id)],
            'notes' => ['required', 'string', 'max:2000'],
            'promise_to_pay_on' => ['nullable', 'date'],
        ]);
        $service->logContact($agreement, $data, $request->user());

        return back()->with('success', 'Collection note saved.');
    }

    public function updateStatus(Request $request, InstallmentAgreement $agreement): RedirectResponse
    {
        abort_unless($request->user()->role->canOverride(), 403);
        $this->assertAccessible($request, $agreement);
        $data = $request->validate(['status' => ['required', Rule::in(['defaulted', 'written_off', 'cancelled'])], 'reason' => ['required', 'string', 'max:255']]);
        abort_unless($agreement->status === 'active', 409);
        if ($data['status'] === 'cancelled' && ($agreement->down_payment > 0 || $agreement->payments()->exists())) {
            throw ValidationException::withMessages(['status' => 'An agreement with a collected payment cannot be cancelled. Use defaulted or written-off status and follow your refund/accounting process.']);
        }
        $before = $agreement->status;
        $agreement->update(['status' => $data['status']]);
        AuditLog::record('installment.status_changed', null, "Agreement {$agreement->number} marked {$data['status']}: {$data['reason']}", ['status' => $before], ['status' => $data['status']], $request->user());

        return back()->with('success', "Agreement marked {$data['status']}.");
    }

    public function agreementPdf(Request $request, InstallmentAgreement $agreement): HttpResponse
    {
        $this->assertAccessible($request, $agreement);
        $agreement->load(['customer', 'location', 'salesOrder', 'plan', 'schedule', 'guarantors']);
        $pdf = SalesDocuments::renderPdf('documents.installment-agreement', ['agreement' => $agreement, 'company' => CompanySetting::current()]);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$agreement->number.'.pdf"',
        ]);
    }

    public function signedDocument(Request $request, InstallmentAgreement $agreement, InstallmentService $service)
    {
        $this->assertAccessible($request, $agreement);

        return $service->signedDocument($agreement);
    }

    public function customerStatement(Request $request, Customer $customer): HttpResponse
    {
        $agreements = InstallmentAgreement::query()->visibleTo($request->user())
            ->where('customer_id', $customer->id)
            ->with(['location', 'plan', 'salesOrder', 'schedule', 'payments'])
            ->orderBy('start_date')
            ->get();
        abort_if($agreements->isEmpty(), 404);
        $pdf = SalesDocuments::renderPdf('documents.installment-statement', [
            'customer' => $customer,
            'agreements' => $agreements,
            'company' => CompanySetting::current(),
        ]);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="statement-'.$customer->id.'.pdf"',
        ]);
    }

    public function setup(Request $request): Response
    {
        return Inertia::render('Admin/Installments/Setup', [
            'plans' => InstallmentPlan::query()->orderBy('name')->get()->map(fn (InstallmentPlan $plan) => $this->planData($plan)),
            'settings' => $this->settingsData(CompanySetting::current()),
            'canManagePolicy' => $request->user()->role === Role::Admin,
        ]);
    }

    public function storePlan(Request $request): RedirectResponse
    {
        abort_unless($request->user()->role->canOverride(), 403);
        $data = $this->validatePlan($request);
        $plan = InstallmentPlan::query()->create($this->planAttributes($data));
        AuditLog::record('installment.plan_created', null, "Created installment plan {$plan->name}", null, ['plan_id' => $plan->id], $request->user());

        return back()->with('success', 'Installment plan saved.');
    }

    public function updatePlan(Request $request, InstallmentPlan $plan): RedirectResponse
    {
        abort_unless($request->user()->role->canOverride(), 403);
        $data = $this->validatePlan($request);
        $before = $plan->only(['name', 'terms', 'minimum_down_payment_bp', 'charge_method', 'flat_bp', 'document_fee_cents', 'late_fee_method', 'late_fee_cents', 'late_fee_bp', 'grace_days', 'is_active']);
        $plan->update($this->planAttributes($data));
        AuditLog::record('installment.plan_updated', null, "Updated installment plan {$plan->name}", $before, $plan->only(array_keys($before)), $request->user());

        return back()->with('success', 'Installment plan saved.');
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        Gate::authorize('manage-settings');
        $data = $request->validate([
            'agreement_terms' => ['nullable', 'string', 'max:12000'],
            'tax_treatment' => ['nullable', Rule::in(['non_taxable', 'vat_inclusive', 'vat_exclusive'])],
            'early_settlement_method' => ['nullable', Rule::in(['none', 'charge_rebate'])],
            'charge_rebate_percent' => ['nullable', 'string', 'regex:/^\d{1,3}(?:\.\d{1,2})?$/', 'max:10'],
            'ownership_until_paid' => ['nullable', Rule::in(['yes', 'no'])],
            'reminder_stages' => ['required', 'array', 'min:1', 'max:12'],
            'reminder_stages.*' => ['required', 'string', 'regex:/^(before|overdue):[1-9][0-9]{0,2}$/', 'distinct'],
        ]);
        if (($data['early_settlement_method'] ?? null) === 'charge_rebate' && blank($data['charge_rebate_percent'] ?? null)) {
            throw ValidationException::withMessages(['charge_rebate_percent' => 'Enter the approved charge rebate percentage.']);
        }
        if (in_array($data['tax_treatment'] ?? null, ['vat_inclusive', 'vat_exclusive'], true) && ! CompanySetting::current()->vat_registered) {
            throw ValidationException::withMessages(['tax_treatment' => 'Set up the company VAT registration before selecting a VAT treatment.']);
        }

        $settings = CompanySetting::current();
        $rebateBp = filled($data['charge_rebate_percent'] ?? null) ? $this->parsePercent($data['charge_rebate_percent'], 'charge_rebate_percent') : null;
        $before = $settings->only(['installment_agreement_terms', 'installment_tax_treatment', 'installment_early_settlement_method', 'installment_charge_rebate_bp', 'installment_ownership_until_paid', 'installment_reminder_stages']);
        $settings->update([
            'installment_agreement_terms' => $data['agreement_terms'] ?? null,
            'installment_tax_treatment' => $data['tax_treatment'] ?? null,
            'installment_early_settlement_method' => $data['early_settlement_method'] ?? null,
            'installment_charge_rebate_bp' => $rebateBp,
            'installment_ownership_until_paid' => match ($data['ownership_until_paid'] ?? null) {
                'yes' => true,
                'no' => false,
                default => null,
            },
            'installment_reminder_stages' => array_values($data['reminder_stages']),
        ]);
        AuditLog::record('installment.policy_updated', null, 'Updated installment wording, tax treatment, settlement policy and reminders', $before, $settings->only(array_keys($before)), $request->user());

        return back()->with('success', 'Installment policy saved.');
    }

    private function validatePayment(Request $request): array
    {
        $data = $request->validate([
            'payment_method_id' => ['required', 'integer', 'exists:payment_methods,id'],
            'amount_cents' => ['required', 'integer', 'min:1'],
            'tendered_cents' => ['nullable', 'integer', 'min:0'],
            'reference' => ['nullable', 'string', 'max:100'],
            'cheque_bank' => ['nullable', 'string', 'max:80'],
            'cheque_branch' => ['nullable', 'string', 'max:80'],
            'cheque_number' => ['nullable', 'string', 'max:30'],
            'cheque_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        return [
            'payment_method_id' => (int) $data['payment_method_id'],
            'amount' => (int) $data['amount_cents'],
            'tendered' => isset($data['tendered_cents']) ? (int) $data['tendered_cents'] : null,
            'reference' => $data['reference'] ?? null,
            'cheque_bank' => $data['cheque_bank'] ?? null,
            'cheque_branch' => $data['cheque_branch'] ?? null,
            'cheque_number' => $data['cheque_number'] ?? null,
            'cheque_date' => $data['cheque_date'] ?? null,
            'notes' => $data['notes'] ?? null,
        ];
    }

    private function validatePlan(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'terms' => ['nullable', 'array', 'max:12'],
            'terms.*' => ['required', 'integer', 'min:1', 'max:120', 'distinct'],
            'minimum_down_payment_percent' => ['nullable', 'string', 'regex:/^\d{1,3}(?:\.\d{1,2})?$/', 'max:10'],
            'charge_method' => ['required', Rule::in(['none', 'flat_bp'])],
            'flat_percent' => ['nullable', 'string', 'regex:/^\d{1,3}(?:\.\d{1,2})?$/', 'max:10'],
            'document_fee' => ['nullable', 'string', 'regex:/^\d+(?:\.\d{1,2})?$/', 'max:15'],
            'late_fee_method' => ['nullable', Rule::in(['fixed', 'monthly_bp'])],
            'late_fee_amount' => ['nullable', 'string', 'regex:/^\d+(?:\.\d{1,2})?$/', 'max:15'],
            'late_fee_percent' => ['nullable', 'string', 'regex:/^\d{1,3}(?:\.\d{1,2})?$/', 'max:10'],
            'grace_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'is_active' => ['required', 'boolean'],
        ]);
    }

    private function planAttributes(array $data): array
    {
        $active = (bool) $data['is_active'];
        if ($active && (empty($data['terms'])
            || blank($data['minimum_down_payment_percent'] ?? null)
            || blank($data['document_fee'] ?? null)
            || blank($data['late_fee_method'] ?? null)
            || ! isset($data['grace_days']) || $data['grace_days'] === '')) {
            throw ValidationException::withMessages(['is_active' => 'Set terms, minimum down payment, document fee, late-fee rule and grace days before activating the plan.']);
        }
        if ($active && $data['late_fee_method'] === 'fixed' && blank($data['late_fee_amount'] ?? null)) {
            throw ValidationException::withMessages(['late_fee_amount' => 'Set the fixed late fee (use 0 if there is no charge).']);
        }
        if ($active && $data['late_fee_method'] === 'monthly_bp' && blank($data['late_fee_percent'] ?? null)) {
            throw ValidationException::withMessages(['late_fee_percent' => 'Set the monthly late-fee rate.']);
        }
        if ($active && $data['charge_method'] === 'flat_bp' && blank($data['flat_percent'] ?? null)) {
            throw ValidationException::withMessages(['flat_percent' => 'Set the flat charge percentage.']);
        }
        if ($active && ! $this->globalPolicyReady(CompanySetting::current())) {
            throw ValidationException::withMessages(['is_active' => 'Configure the accountant-approved agreement wording and tax treatment, early-settlement policy, and ownership policy before activating plans.']);
        }

        return [
            'name' => trim($data['name']),
            'terms' => isset($data['terms']) ? array_map('intval', $data['terms']) : null,
            'minimum_down_payment_bp' => filled($data['minimum_down_payment_percent'] ?? null) ? $this->parsePercent($data['minimum_down_payment_percent'], 'minimum_down_payment_percent') : null,
            'charge_method' => $data['charge_method'],
            'flat_bp' => filled($data['flat_percent'] ?? null) ? $this->parsePercent($data['flat_percent'], 'flat_percent') : 0,
            'document_fee_cents' => filled($data['document_fee'] ?? null) ? Money::fromDecimal($data['document_fee'])->cents : null,
            'late_fee_method' => $data['late_fee_method'] ?? null,
            'late_fee_cents' => filled($data['late_fee_amount'] ?? null) ? Money::fromDecimal($data['late_fee_amount'])->cents : null,
            'late_fee_bp' => filled($data['late_fee_percent'] ?? null) ? $this->parsePercent($data['late_fee_percent'], 'late_fee_percent') : null,
            'grace_days' => isset($data['grace_days']) ? (int) $data['grace_days'] : null,
            'is_active' => $active,
        ];
    }

    private function assertAccessible(Request $request, InstallmentAgreement $agreement): void
    {
        abort_unless($request->user()->worksAt($agreement->location_id), 403);
    }

    private function agreementSummary(InstallmentAgreement $agreement): array
    {
        $remaining = (int) ($agreement->schedule?->sum(fn (InstallmentSchedule $line) => max(0, $line->amount_due - $line->amount_paid + $line->late_fee_charged - $line->late_fee_paid)) ?? 0);

        return [
            'id' => $agreement->id,
            'number' => $agreement->number,
            'status' => $agreement->status,
            'customer' => $agreement->customer?->name,
            'customer_id' => $agreement->customer_id,
            'nic' => $agreement->customer?->nic,
            'sales_order' => $agreement->salesOrder?->invoice_number ?? $agreement->salesOrder?->number,
            'location' => $agreement->location?->name,
            'location_code' => $agreement->location?->code,
            'plan' => $agreement->plan?->name,
            'financed_amount' => $agreement->financed_amount,
            'down_payment' => $agreement->down_payment,
            'charges' => $agreement->charges,
            'total_payable' => $agreement->total_payable,
            'tax_cents' => $agreement->tax_cents,
            'tax_treatment' => $agreement->tax_treatment_snapshot,
            'ownership_until_paid' => $agreement->ownership_until_paid_snapshot,
            'remaining' => $remaining,
            'term_months' => $agreement->term_months,
            'start_date' => $agreement->start_date?->toDateString(),
            'schedule_count' => $agreement->schedule_count ?? $agreement->schedule?->count(),
            'signed_agreement_available' => (bool) $agreement->signed_agreement_path,
        ];
    }

    private function scheduleSummary(InstallmentSchedule $line): array
    {
        return [
            'id' => $line->id,
            'due_date' => $line->due_date->toDateString(),
            'principal' => $line->principal,
            'charge' => $line->charge,
            'amount_due' => $line->amount_due,
            'amount_paid' => $line->amount_paid,
            'principal_paid' => $line->principal_paid,
            'late_fee_charged' => $line->late_fee_charged,
            'late_fee_paid' => $line->late_fee_paid,
            'remaining' => max(0, $line->amount_due - $line->amount_paid) + max(0, $line->late_fee_charged - $line->late_fee_paid),
            'status' => $line->status,
            'agreement_id' => $line->installment_agreement_id,
            'agreement_number' => $line->agreement?->number,
            'customer' => $line->agreement?->customer?->name,
        ];
    }

    private function planData(InstallmentPlan $plan): array
    {
        return [
            'id' => $plan->id,
            'name' => $plan->name,
            'terms' => $plan->terms ?? [],
            'minimum_down_payment_percent' => $plan->minimum_down_payment_bp === null ? '' : BasisPoints::toPercent($plan->minimum_down_payment_bp),
            'charge_method' => $plan->charge_method,
            'flat_percent' => BasisPoints::toPercent((int) $plan->flat_bp),
            'document_fee' => $plan->document_fee_cents === null ? '' : Money::fromCents($plan->document_fee_cents)->toDecimal(),
            'late_fee_method' => $plan->late_fee_method ?? '',
            'late_fee_amount' => $plan->late_fee_cents === null ? '' : Money::fromCents($plan->late_fee_cents)->toDecimal(),
            'late_fee_percent' => $plan->late_fee_bp === null ? '' : BasisPoints::toPercent($plan->late_fee_bp),
            'grace_days' => $plan->grace_days,
            'is_active' => $plan->is_active,
        ];
    }

    private function settingsData(CompanySetting $settings): array
    {
        return [
            'agreement_terms' => $settings->installment_agreement_terms ?? '',
            'tax_treatment' => $settings->installment_tax_treatment ?? '',
            'early_settlement_method' => $settings->installment_early_settlement_method ?? '',
            'charge_rebate_percent' => $settings->installment_charge_rebate_bp === null ? '' : BasisPoints::toPercent($settings->installment_charge_rebate_bp),
            'ownership_until_paid' => $settings->installment_ownership_until_paid === null ? '' : ($settings->installment_ownership_until_paid ? 'yes' : 'no'),
            'vat_registered' => $settings->vat_registered,
            'vat_rate_percent' => $settings->vatRatePercent(),
            'reminder_stages' => $settings->installment_reminder_stages ?: ['before:1', 'overdue:1', 'overdue:7', 'overdue:30'],
        ];
    }

    private function globalPolicyReady(CompanySetting $settings): bool
    {
        return filled($settings->installment_agreement_terms)
            && in_array($settings->installment_tax_treatment, ['non_taxable', 'vat_inclusive', 'vat_exclusive'], true)
            && in_array($settings->installment_early_settlement_method, ['none', 'charge_rebate'], true)
            && ($settings->installment_early_settlement_method !== 'charge_rebate' || $settings->installment_charge_rebate_bp !== null)
            && $settings->installment_ownership_until_paid !== null
            && (! in_array($settings->installment_tax_treatment, ['vat_inclusive', 'vat_exclusive'], true) || $settings->vat_registered);
    }

    private function parsePercent(string $value, string $field): int
    {
        $basisPoints = BasisPoints::fromPercent($value);
        if ($basisPoints > 10000) {
            throw ValidationException::withMessages([$field => 'Percentage cannot exceed 100%.']);
        }

        return $basisPoints;
    }
}
