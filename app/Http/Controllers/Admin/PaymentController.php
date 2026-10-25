<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ChequeStatus;
use App\Enums\PaymentRecordStatus;
use App\Exceptions\ApprovalRefused;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\SalesOrder;
use App\Services\Sales\PaymentService;
use App\Support\Money;
use App\Support\SalesDocuments;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * Payment receipts: list, take a payment on an order, void (manager), cheque tracking, PDF.
 */
class PaymentController extends Controller
{
    public function index(Request $request): Response
    {
        $search = trim((string) $request->string('search'));
        $method = $request->integer('method_id') ?: null;
        $cheques = (string) $request->string('cheque');

        $payments = Payment::query()
            ->forLocation()
            ->with(['method:id,name,code', 'customer:id,name,phone', 'salesOrder:id,number,invoice_number', 'location:id,code', 'receivedBy:id,name'])
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('number', 'like', "%{$search}%")
                ->orWhere('reference', 'like', "%{$search}%")
                ->orWhere('cheque_number', 'like', "%{$search}%")
                ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%"))
                ->orWhereHas('salesOrder', fn ($o) => $o->where('number', 'like', "%{$search}%")->orWhere('invoice_number', 'like', "%{$search}%"))))
            ->when($method, fn ($q) => $q->where('payment_method_id', $method))
            ->when($cheques !== '', fn ($q) => $q->where('cheque_status', $cheques))
            ->latest('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Payment $p) => $this->row($p));

        return Inertia::render('Admin/Sales/Payments/Index', [
            'payments' => $payments,
            'filters' => ['search' => $search, 'method_id' => $method, 'cheque' => $cheques],
            'methods' => PaymentMethod::query()->active()->get(['id', 'name'])->map->only(['id', 'name']),
            'chequeStatuses' => ChequeStatus::options(),
            'canManageCheques' => $request->user()->can('manage-cheques'),
        ]);
    }

    public function store(Request $request, SalesOrder $order, PaymentService $service): RedirectResponse
    {
        Gate::authorize('manage-sales');
        abort_unless($request->user()->worksAt($order->location_id), 403);

        $validated = $request->validate([
            'payment_method_id' => ['required', 'integer', 'exists:payment_methods,id'],
            'amount' => ['nullable', 'string', 'max:20'],
            'tendered' => ['nullable', 'string', 'max:20'],
            'reference' => ['nullable', 'string', 'max:100'],
            'credit_note_id' => ['nullable', 'integer'],
            'cheque_bank' => ['nullable', 'string', 'max:80'],
            'cheque_branch' => ['nullable', 'string', 'max:80'],
            'cheque_number' => ['nullable', 'string', 'max:30'],
            'cheque_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $payment = $service->receive($order, [
                ...$validated,
                'amount' => filled($validated['amount'] ?? null) ? Money::fromDecimal($validated['amount'])->cents : 0,
                'tendered' => filled($validated['tendered'] ?? null) ? Money::fromDecimal($validated['tendered'])->cents : null,
            ], $request->user());
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['payment' => $e->getMessage()]);
        }

        $change = $payment->change_given ? ' Change: '.Money::fromCents($payment->change_given)->format().'.' : '';

        return back()->with('success', "Payment {$payment->number} of ".Money::fromCents($payment->amount)->format()." received.{$change}");
    }

    public function void(Request $request, Payment $payment, PaymentService $service): RedirectResponse
    {
        Gate::authorize('manage-sales');
        abort_unless($request->user()->worksAt($payment->location_id), 403);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
            'approver_id' => ['nullable', 'integer'],
            'pin' => ['nullable', 'string', 'max:10'],
        ]);

        try {
            $service->void($payment, $request->user(), $validated['reason'], $validated['approver_id'] ?? null, $validated['pin'] ?? null);
        } catch (ApprovalRefused $e) {
            throw ValidationException::withMessages(['pin' => $e->getMessage()]);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['payment' => $e->getMessage()]);
        }

        return back()->with('success', "Payment {$payment->number} voided.");
    }

    public function cheque(Request $request, Payment $payment, PaymentService $service): RedirectResponse
    {
        Gate::authorize('manage-cheques');
        abort_unless($request->user()->worksAt($payment->location_id), 403);

        $validated = $request->validate(['status' => ['required', Rule::enum(ChequeStatus::class)]]);

        try {
            $service->setChequeStatus($payment, ChequeStatus::from($validated['status']), $request->user());
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['payment' => $e->getMessage()]);
        }

        return back()->with('success', "Cheque on {$payment->number} marked ".ChequeStatus::from($validated['status'])->label().'.');
    }

    public function pdf(Request $request, Payment $payment): HttpResponse
    {
        abort_unless($request->user()->worksAt($payment->location_id), 403);

        return response(SalesDocuments::receiptPdf($payment), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$payment->number.'.pdf"',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function row(Payment $p): array
    {
        return [
            'id' => $p->id,
            'number' => $p->number,
            'date' => $p->business_date?->toDateString(),
            'created_at' => $p->created_at?->toIso8601String(),
            'location_code' => $p->location?->code,
            'method' => $p->method?->name,
            'method_code' => $p->method?->code->value,
            'amount' => $p->amount,
            'tendered' => $p->tendered,
            'change_given' => $p->change_given,
            'reference' => $p->reference,
            'customer' => $p->customer?->name,
            'order_id' => $p->sales_order_id,
            'order_number' => $p->salesOrder?->invoice_number ?? $p->salesOrder?->number,
            'cheque' => $p->cheque_number ? [
                'number' => $p->cheque_number,
                'bank' => $p->cheque_bank,
                'branch' => $p->cheque_branch,
                'date' => $p->cheque_date?->toDateString(),
                'status' => $p->cheque_status?->value,
                'status_label' => $p->cheque_status?->label(),
                'next' => array_map(fn (ChequeStatus $s) => ['value' => $s->value, 'label' => $s->label()], $p->cheque_status?->next() ?? []),
            ] : null,
            'status' => $p->status->value,
            'is_voided' => $p->status === PaymentRecordStatus::Voided,
            'counts' => $p->counts(),
            'void_reason' => $p->void_reason,
            'received_by' => $p->receivedBy?->name,
            'pdf_url' => route('sales.payments.pdf', $p),
        ];
    }
}
