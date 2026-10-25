<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PaymentMethodCode;
use App\Exceptions\ApprovalRefused;
use App\Http\Controllers\Controller;
use App\Models\CreditNote;
use App\Models\PaymentMethod;
use App\Services\Sales\CreditNoteService;
use App\Support\CurrentLocation;
use App\Support\ManagerApproval;
use App\Support\Money;
use App\Support\SalesDocuments;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class CreditNoteController extends Controller
{
    public function index(Request $request): Response
    {
        $search = trim((string) $request->string('search'));

        $notes = CreditNote::query()
            ->forLocation()
            ->with(['customer:id,name,phone', 'salesOrder:id,number,invoice_number', 'location:id,code'])
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('number', 'like', "%{$search}%")
                ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%"))))
            ->latest('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (CreditNote $n) => [
                'id' => $n->id,
                'number' => $n->number,
                'location_id' => $n->location_id,
                'location_code' => $n->location?->code,
                'customer' => $n->customer?->name,
                'order_id' => $n->sales_order_id,
                'order_number' => $n->salesOrder?->invoice_number ?? $n->salesOrder?->number,
                'amount' => $n->amount,
                'remaining' => $n->remaining,
                'expires_on' => $n->expires_on?->toDateString(),
                'status_label' => $n->isExpired() && $n->remaining > 0 ? 'Expired' : $n->status->label(),
                'usable' => $n->isUsable(),
                'reason' => $n->reason,
                'created_at' => $n->created_at?->toIso8601String(),
                'pdf_url' => route('sales.credit-notes.pdf', $n),
                'public_url' => route('documents.show', $n->share_token),
            ]);

        $locationId = CurrentLocation::id() ?? $request->user()->home_location_id;

        return Inertia::render('Admin/Sales/CreditNotes/Index', [
            'creditNotes' => $notes,
            'filters' => ['search' => $search],
            'refundMethods' => PaymentMethod::query()->active()->where('code', '!=', PaymentMethodCode::CreditNote->value)->get()->map->toOption()->values(),
            'approvers' => $locationId ? ManagerApproval::approvers((int) $locationId) : [],
            'canOverride' => $request->user()->can('override'),
            'canManage' => $request->user()->can('manage-sales'),
        ]);
    }

    public function refund(Request $request, CreditNote $creditNote, CreditNoteService $service): RedirectResponse
    {
        Gate::authorize('manage-sales');
        abort_unless($request->user()->worksAt($creditNote->location_id), 403);

        $validated = $request->validate([
            'payment_method_id' => ['required', 'integer', 'exists:payment_methods,id'],
            'amount' => ['required', 'string', 'max:20'],
            'reason' => ['required', 'string', 'max:255'],
            'reference' => ['nullable', 'string', 'max:100'],
            'approver_id' => ['nullable', 'integer'],
            'pin' => ['nullable', 'string', 'max:10'],
        ]);

        try {
            $refund = $service->refund(
                $creditNote,
                (int) $validated['payment_method_id'],
                Money::fromDecimal($validated['amount'])->cents,
                $validated['reason'],
                $request->user(),
                $validated['reference'] ?? null,
                $validated['approver_id'] ?? null,
                $validated['pin'] ?? null,
            );
        } catch (ApprovalRefused $e) {
            throw ValidationException::withMessages(['pin' => $e->getMessage()]);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['amount' => $e->getMessage()]);
        }

        return back()->with('success', "Refund {$refund->number} of ".Money::fromCents($refund->amount)->format()." recorded from {$creditNote->number}.");
    }

    public function pdf(Request $request, CreditNote $creditNote): HttpResponse
    {
        abort_unless($request->user()->worksAt($creditNote->location_id), 403);

        return response(SalesDocuments::creditNotePdf($creditNote), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$creditNote->number.'.pdf"',
        ]);
    }
}
