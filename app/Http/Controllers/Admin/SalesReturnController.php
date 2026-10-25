<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ReturnCondition;
use App\Enums\ReturnOutcome;
use App\Exceptions\ApprovalRefused;
use App\Http\Controllers\Controller;
use App\Models\SalesOrder;
use App\Models\SalesReturn;
use App\Services\Sales\ReturnService;
use App\Support\Money;
use App\Support\Quantity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class SalesReturnController extends Controller
{
    public function index(Request $request): Response
    {
        $search = trim((string) $request->string('search'));

        $returns = SalesReturn::query()
            ->forLocation()
            ->with(['salesOrder:id,number,invoice_number', 'customer:id,name', 'location:id,code', 'createdBy:id,name', 'approvedBy:id,name', 'creditNote:id,sales_return_id,number', 'refund:id,sales_return_id,number', 'exchangeOrder:id,number'])
            ->withCount('lines')
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('number', 'like', "%{$search}%")
                ->orWhereHas('salesOrder', fn ($o) => $o->where('number', 'like', "%{$search}%")->orWhere('invoice_number', 'like', "%{$search}%"))
                ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$search}%"))))
            ->latest('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (SalesReturn $r) => [
                'id' => $r->id,
                'number' => $r->number,
                'date' => $r->business_date?->toDateString(),
                'location_code' => $r->location?->code,
                'order_id' => $r->sales_order_id,
                'order_number' => $r->salesOrder?->invoice_number ?? $r->salesOrder?->number,
                'customer' => $r->customer?->name,
                'outcome_label' => $r->outcome->label(),
                'total' => $r->total,
                'settled_amount' => $r->settled_amount,
                'lines_count' => $r->lines_count,
                'reason' => $r->reason,
                'created_by' => $r->createdBy?->name,
                'approved_by' => $r->approvedBy?->name,
                'credit_note' => $r->creditNote?->only(['id', 'number']),
                'refund_number' => $r->refund?->number,
                'exchange_order' => $r->exchangeOrder?->only(['id', 'number']),
            ]);

        return Inertia::render('Admin/Sales/Returns/Index', [
            'returns' => $returns,
            'filters' => ['search' => $search],
        ]);
    }

    public function store(Request $request, SalesOrder $order, ReturnService $service): RedirectResponse
    {
        Gate::authorize('manage-sales');
        abort_unless($request->user()->worksAt($order->location_id), 403);

        $validated = $request->validate([
            'outcome' => ['required', Rule::enum(ReturnOutcome::class)],
            'reason' => ['required', 'string', 'max:255'],
            'refund_method_id' => ['nullable', 'integer', 'exists:payment_methods,id'],
            'refund_reference' => ['nullable', 'string', 'max:100'],
            'approver_id' => ['nullable', 'integer'],
            'pin' => ['nullable', 'string', 'max:10'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.sales_order_line_id' => ['required', 'integer'],
            'lines.*.quantity' => ['nullable', 'string', 'max:20'],
            'lines.*.condition' => ['required', Rule::enum(ReturnCondition::class)],
            'lines.*.restock_location_id' => ['nullable', 'integer', 'exists:locations,id'],
        ]);

        $lines = collect($validated['lines'])
            ->filter(fn ($l) => filled($l['quantity'] ?? null) && Quantity::parse($l['quantity']) > 0)
            ->map(fn ($l) => [...$l, 'quantity' => Quantity::parse($l['quantity'])])
            ->values()
            ->all();

        try {
            $return = $service->process(
                $order,
                $lines,
                ReturnOutcome::from($validated['outcome']),
                $validated['reason'],
                $request->user(),
                $validated['refund_method_id'] ?? null,
                $validated['refund_reference'] ?? null,
                $validated['approver_id'] ?? null,
                $validated['pin'] ?? null,
            );
        } catch (ApprovalRefused $e) {
            throw ValidationException::withMessages(['pin' => $e->getMessage()]);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['return' => $e->getMessage()]);
        }

        $settled = match (true) {
            $return->settled_amount === 0 => 'The balance due was reduced.',
            $return->creditNote !== null => "Credit note {$return->creditNote->number} issued for ".Money::fromCents($return->settled_amount)->format().'.',
            default => 'Refund '.($return->refund?->number ?? '').' of '.Money::fromCents($return->settled_amount)->format().' recorded.',
        };

        return back()->with('success', "Return {$return->number} recorded. {$settled}");
    }
}
