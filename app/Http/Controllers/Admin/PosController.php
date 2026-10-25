<?php

namespace App\Http\Controllers\Admin;

use App\Enums\FulfilmentType;
use App\Enums\ProductType;
use App\Enums\SalesLineSource;
use App\Enums\SalesOrderKind;
use App\Exceptions\ApprovalRefused;
use App\Exceptions\CashActionRefused;
use App\Exceptions\InsufficientStock;
use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\CompanySetting;
use App\Models\CreditNote;
use App\Models\Location;
use App\Models\PaymentMethod;
use App\Models\PosHeldCart;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\StockLevel;
use App\Services\Cash\ShiftService;
use App\Services\Sales\DiscountApprovalService;
use App\Services\Sales\PosCheckoutService;
use App\Services\Sales\PricingService;
use App\Support\BasisPoints;
use App\Support\CurrentLocation;
use App\Support\DeviceRegister;
use App\Support\ManagerApproval;
use App\Support\Money;
use App\Support\Quantity;
use App\Support\SalesDocuments;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * The counter till for the current location: search/scan, cart, pay pad, hold/resume, print.
 */
class PosController extends Controller
{
    public function index(Request $request, DiscountApprovalService $discounts, ShiftService $shifts): Response
    {
        $user = $request->user();
        $location = CurrentLocation::get() ?? Location::query()->findOrFail($user->home_location_id);
        $settings = CompanySetting::current();
        $shift = $shifts->forTransaction($user, $location->id, DeviceRegister::id($request, $location->id));

        return Inertia::render('Admin/Pos/Index', [
            'categories' => Category::query()
                ->where('is_active', true)
                ->whereNull('parent_id')
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map->only(['id', 'name'])
                ->values(),
            'paymentMethods' => PaymentMethod::query()->active()->get()->map->toOption()->values(),
            'approvers' => ManagerApproval::approvers($location->id),
            'heldCarts' => $this->heldCarts($location->id),
            'fulfilmentTypes' => FulfilmentType::options(),
            'pricingConfig' => [
                'tax_rate_bp' => $settings->vat_registered ? (int) $settings->vat_rate_bp : 0,
                'prices_include_tax' => (bool) $settings->prices_include_vat,
                'rounding_unit' => (int) $settings->cash_rounding_unit,
                'max_discount_bp' => $discounts->maxDiscountBpFor($user),
            ],
            'canOverride' => $user->can('override'),
            'shift' => $shift ? ['id' => $shift->id, 'register' => $shift->register()->value('name'), 'own' => $shift->user_id === $user->id] : null,
            'requireShift' => (bool) $settings->require_shift_for_payments,
        ]);
    }

    /**
     * Product search / barcode scan / category browse with live availability here and elsewhere.
     */
    public function products(Request $request): JsonResponse
    {
        $locationId = $this->locationId($request);
        $q = trim((string) $request->string('q'));
        $categoryId = $request->integer('category_id') ?: null;
        $canViewCost = $request->user()->can('view-cost');

        if ($q === '' && ! $categoryId) {
            return response()->json(['variants' => [], 'exact' => false]);
        }

        // A scanned barcode or typed SKU goes straight into the cart.
        $exact = $q !== '' ? ProductVariant::query()
            ->where('is_active', true)
            ->where(fn ($w) => $w->where('barcode', $q)->orWhere('sku', $q))
            ->whereHas('product', fn ($p) => $p->where('is_active', true))
            ->first() : null;

        $variants = $exact ? $exact->newCollection([$exact]) : ProductVariant::query()
            ->where('is_active', true)
            ->whereHas('product', fn ($p) => $p->where('is_active', true)
                ->when($categoryId, fn ($c) => $c->whereIn('category_id', $this->categoryIds($categoryId))))
            ->when($q !== '', fn ($w) => $w->where(fn ($s) => $s->where('sku', 'like', "%{$q}%")
                ->orWhere('barcode', 'like', "{$q}%")
                ->orWhereHas('product', fn ($p) => $p->where('name', 'like', "%{$q}%"))))
            ->orderBy('sku')
            ->limit(40)
            ->get();

        $variants->load(['product:id,name,type,is_serialized', 'product.images' => fn ($i) => $i->orderBy('sort_order'), 'attributeValues.attribute']);

        $levels = StockLevel::query()
            ->whereIn('product_variant_id', $variants->pluck('id'))
            ->whereRaw('on_hand - reserved > 0')
            ->with('location:id,name,code,is_active')
            ->get()
            ->groupBy('product_variant_id');

        return response()->json([
            'exact' => (bool) $exact,
            'variants' => $variants->map(function (ProductVariant $v) use ($levels, $locationId, $canViewCost) {
                $here = 0;
                $elsewhere = [];
                foreach ($levels->get($v->id, collect()) as $level) {
                    $available = $level->on_hand - $level->reserved;
                    if ($level->location_id === $locationId) {
                        $here = $available;
                    } elseif ($level->location?->is_active) {
                        $elsewhere[] = ['location' => $level->location->name, 'code' => $level->location->code, 'available' => Quantity::format($available)];
                    }
                }
                $image = $v->product?->images->firstWhere('product_variant_id', $v->id) ?? $v->product?->images->first();

                return [
                    'id' => $v->id,
                    'sku' => $v->sku,
                    'barcode' => $v->barcode,
                    'name' => trim(($v->product?->name ?? $v->sku).($v->name ? ' — '.$v->name : '')),
                    'product_id' => $v->product_id,
                    'options' => $v->attributeValues->map(fn ($av) => ($av->attribute?->name ?? 'Option').': '.$av->value)->implode(' · '),
                    'type' => $v->product?->type?->value ?? ProductType::Stock->value,
                    'is_service' => $v->product?->type === ProductType::Service,
                    'is_serialized' => (bool) $v->product?->is_serialized,
                    'default_source' => $v->product?->type === ProductType::MadeToOrder ? SalesLineSource::Make->value : SalesLineSource::Stock->value,
                    'price' => Money::fromCents($v->price)->toDecimal(),
                    'price_cents' => $v->price,
                    'min_price_cents' => $v->min_price,
                    'available_here' => Quantity::format($here),
                    'available_here_milli' => $here,
                    'elsewhere' => $elsewhere,
                    'thumbnail' => $image?->thumbnailUrl(),
                    ...($canViewCost ? ['cost_cents' => (int) ($v->average_cost ?: $v->last_cost)] : []),
                ];
            })->values(),
        ]);
    }

    /**
     * Server-side cart totals (PricingService) for the till, and whether the discounts or prices
     * will need a manager's PIN at checkout. Nothing is saved or audited here.
     */
    public function price(Request $request, PricingService $pricing, DiscountApprovalService $discounts): JsonResponse
    {
        $validated = $request->validate([
            'order_discount_type' => ['nullable', 'in:fixed,percent'],
            'order_discount_value' => ['nullable', 'string', 'max:20'],
            'lines' => ['present', 'array', 'max:200'],
            'lines.*.product_variant_id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'string', 'max:20'],
            'lines.*.unit_price' => ['required', 'string', 'max:20'],
            'lines.*.discount_type' => ['nullable', 'in:fixed,percent'],
            'lines.*.discount_value' => ['nullable', 'string', 'max:20'],
        ]);

        $settings = CompanySetting::current();
        $variants = ProductVariant::query()->with('product:id,type')->whereIn('id', array_column($validated['lines'], 'product_variant_id'))->get()->keyBy('id');

        try {
            $lines = array_map(fn (array $l) => [
                'quantity' => Quantity::parse($l['quantity']),
                'unit_price' => Money::fromDecimal($l['unit_price'])->cents,
                'discount_type' => $l['discount_type'] ?? null,
                'discount_value' => $this->discountValue($l['discount_type'] ?? null, $l['discount_value'] ?? null),
                'is_service' => $variants->get($l['product_variant_id'])?->product?->type === ProductType::Service,
            ], $validated['lines']);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $priced = $pricing->calculate($lines, [
            'order_discount_type' => $validated['order_discount_type'] ?? null,
            'order_discount_value' => $this->discountValue($validated['order_discount_type'] ?? null, $validated['order_discount_value'] ?? null),
            'tax_rate_bp' => $settings->vat_registered ? (int) $settings->vat_rate_bp : 0,
            'prices_include_tax' => (bool) $settings->prices_include_vat,
            'rounding_unit' => (int) $settings->cash_rounding_unit,
        ]);

        $user = $request->user();
        $limit = $discounts->maxDiscountBpFor($user);
        $reasons = [];
        if (! $user->role->canOverride()) {
            foreach ($priced['lines'] as $i => $line) {
                $variant = $variants->get($validated['lines'][$i]['product_variant_id']);
                if ($variant?->belowMinimum($line['effective_unit_price'])) {
                    $reasons[] = "{$variant->sku} below its minimum price";
                } elseif ($line['effective_discount_bp'] > $limit) {
                    $reasons[] = "{$variant?->sku} discount above ".BasisPoints::toPercent($limit).'%';
                }
            }
            if ($priced['total_discount_bp'] > $limit) {
                $reasons[] = 'order discount above '.BasisPoints::toPercent($limit).'%';
            }
        }

        return response()->json([
            'subtotal' => $priced['subtotal'],
            'line_discount_total' => $priced['line_discount_total'],
            'order_discount_cents' => $priced['order_discount_cents'],
            'tax_total' => $priced['tax_total'],
            'prices_include_tax' => $priced['prices_include_tax'],
            'rounding_cents' => $priced['rounding_cents'],
            'grand_total' => $priced['grand_total'],
            'lines' => array_map(fn ($l) => ['line_total' => $l['line_total'], 'discount_cents' => $l['discount_cents']], $priced['lines']),
            'needs_approval' => $reasons !== [],
            'approval_reasons' => array_values(array_unique($reasons)),
        ]);
    }

    public function checkout(Request $request, PosCheckoutService $pos): JsonResponse
    {
        Gate::authorize('manage-sales');

        $locationId = $this->locationId($request);
        $validated = $request->validate([
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'kind' => ['required', Rule::enum(SalesOrderKind::class)],
            'fulfilment_type' => ['nullable', Rule::enum(FulfilmentType::class)],
            'delivery_address' => ['nullable', 'array'],
            'requested_delivery_date' => ['nullable', 'date'],
            'order_discount_type' => ['nullable', 'in:fixed,percent'],
            'order_discount_value' => ['nullable', 'string', 'max:20'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'approver_id' => ['nullable', 'integer'],
            'pin' => ['nullable', 'string', 'max:10'],
            'held_cart_id' => ['nullable', 'integer'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.product_variant_id' => ['required', 'integer', 'exists:product_variants,id'],
            'lines.*.quantity' => ['required', 'string', 'max:20'],
            'lines.*.unit_price' => ['required', 'string', 'max:20'],
            'lines.*.discount_type' => ['nullable', 'in:fixed,percent'],
            'lines.*.discount_value' => ['nullable', 'string', 'max:20'],
            'lines.*.source' => ['nullable', Rule::enum(SalesLineSource::class)],
            'payments' => ['present', 'array', 'max:10'],
            'payments.*.payment_method_id' => ['required', 'integer', 'exists:payment_methods,id'],
            'payments.*.amount' => ['nullable', 'string', 'max:20'],
            'payments.*.tendered' => ['nullable', 'string', 'max:20'],
            'payments.*.reference' => ['nullable', 'string', 'max:100'],
            'payments.*.credit_note_id' => ['nullable', 'integer'],
            'payments.*.cheque_bank' => ['nullable', 'string', 'max:80'],
            'payments.*.cheque_branch' => ['nullable', 'string', 'max:80'],
            'payments.*.cheque_number' => ['nullable', 'string', 'max:30'],
            'payments.*.cheque_date' => ['nullable', 'date'],
        ]);

        try {
            $cart = $this->cartPayload($validated, $locationId);
            $payments = array_map(fn (array $p) => [
                ...$p,
                'amount' => filled($p['amount'] ?? null) ? Money::fromDecimal($p['amount'])->cents : 0,
                'tendered' => filled($p['tendered'] ?? null) ? Money::fromDecimal($p['tendered'])->cents : null,
            ], $validated['payments']);

            $order = $pos->checkout($cart, $payments, $request->user());
        } catch (ApprovalRefused $e) {
            return $e->toResponse();
        } catch (CashActionRefused $e) {
            return $e->toResponse();
        } catch (InsufficientStock|InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if (! empty($validated['held_cart_id'])) {
            PosHeldCart::query()->where('location_id', $locationId)->whereKey($validated['held_cart_id'])->delete();
        }

        return response()->json(['order' => $this->saleSummary($order)]);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function cartPayload(array $validated, int $locationId): array
    {
        return [
            'customer_id' => $validated['customer_id'] ?? null,
            'location_id' => $locationId,
            'kind' => $validated['kind'],
            'fulfilment_type' => $validated['fulfilment_type'] ?? FulfilmentType::Collect->value,
            'delivery_address' => $validated['delivery_address'] ?? null,
            'requested_delivery_date' => $validated['requested_delivery_date'] ?? null,
            'order_discount_type' => $validated['order_discount_type'] ?? null,
            'order_discount_value' => $this->discountValue($validated['order_discount_type'] ?? null, $validated['order_discount_value'] ?? null),
            'notes' => $validated['notes'] ?? null,
            'approver_id' => $validated['approver_id'] ?? null,
            'pin' => $validated['pin'] ?? null,
            'lines' => array_map(fn (array $l) => [
                'product_variant_id' => (int) $l['product_variant_id'],
                'quantity' => Quantity::parse($l['quantity']),
                'unit_price' => Money::fromDecimal($l['unit_price'])->cents,
                'discount_type' => $l['discount_type'] ?? null,
                'discount_value' => $this->discountValue($l['discount_type'] ?? null, $l['discount_value'] ?? null),
                'source' => $l['source'] ?? null,
                'fulfil_location_id' => $locationId,
            ], $validated['lines']),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function saleSummary(SalesOrder $order): array
    {
        return [
            'id' => $order->id,
            'number' => $order->number,
            'invoice_number' => $order->invoice_number,
            'grand_total' => $order->grand_total,
            'paid_amount' => $order->paid_amount,
            'balance_due' => $order->balance_due,
            'change_given' => (int) $order->payments->sum('change_given'),
            'status_label' => $order->status->label(),
            'opens_cash_drawer' => $order->payments->contains(fn ($p) => $p->method->opens_cash_drawer),
            'receipt_url' => route('pos.receipt', $order),
            'invoice_url' => route('sales.orders.invoice', $order),
            'show_url' => route('sales.orders.show', $order),
            'share' => $this->shareLinks($order),
        ];
    }

    /** Thermal 80 mm receipt, printed from the browser. */
    public function receipt(Request $request, SalesOrder $order): HttpResponse
    {
        abort_unless($request->user()->worksAt($order->location_id), 403);
        SalesOrder::query()->whereKey($order->id)->whereNull('invoice_printed_at')->update(['invoice_printed_at' => now()]);

        $order->load(['location', 'customer', 'salesperson:id,name', 'lines', 'payments' => fn ($q) => $q->counted()->with('method')]);

        return response()->view('documents.thermal', [
            ...SalesDocuments::invoiceData($order),
            'autoPrint' => $request->boolean('print'),
        ]);
    }

    public function creditNote(Request $request): JsonResponse
    {
        $number = strtoupper(trim((string) $request->string('number')));
        $note = CreditNote::query()->with('customer:id,name')->where('number', $number)->first();

        if (! $note) {
            return response()->json(['message' => 'No credit note with that number.'], 404);
        }

        return response()->json(['credit_note' => [
            'id' => $note->id,
            'number' => $note->number,
            'remaining' => Money::fromCents($note->remaining)->toDecimal(),
            'remaining_cents' => $note->remaining,
            'customer_id' => $note->customer_id,
            'customer_name' => $note->customer?->name,
            'expires_on' => $note->expires_on?->toDateString(),
            'usable' => $note->isUsable(),
        ]]);
    }

    public function hold(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'label' => ['required', 'string', 'max:80'],
            'cart' => ['required', 'array'],
            'cart.lines' => ['required', 'array', 'min:1', 'max:200'],
        ]);

        PosHeldCart::query()->create([
            'location_id' => $this->locationId($request),
            'user_id' => $request->user()->id,
            'label' => $validated['label'],
            'cart' => $validated['cart'],
        ]);

        return response()->json(['held' => $this->heldCarts($this->locationId($request))]);
    }

    public function resume(Request $request, PosHeldCart $cart): JsonResponse
    {
        abort_unless($cart->location_id === $this->locationId($request), 404);

        return response()->json(['cart' => $cart->cart, 'id' => $cart->id]);
    }

    public function discard(Request $request, PosHeldCart $cart): JsonResponse
    {
        abort_unless($cart->location_id === $this->locationId($request), 404);
        $cart->delete();

        return response()->json(['held' => $this->heldCarts($this->locationId($request))]);
    }

    /**
     * WhatsApp / SMS links to the public digital copy of an invoice.
     *
     * @return array{url: string, whatsapp: string|null, sms: string|null}
     */
    public static function shareLinks(SalesOrder $order): array
    {
        $order->loadMissing('customer');
        $url = route('documents.show', $order->shareToken());
        $company = CompanySetting::current()->name;
        $text = "Thank you for shopping with {$company}. Your ".SalesDocuments::invoiceTitle()." {$order->invoice_number}: {$url}";
        $digits = $order->customer ? ltrim((string) $order->customer->phone, '+') : null;

        return [
            'url' => $url,
            'whatsapp' => $digits ? 'https://wa.me/'.$digits.'?text='.rawurlencode($text) : 'https://wa.me/?text='.rawurlencode($text),
            'sms' => $order->customer ? 'sms:'.$order->customer->phone.'?body='.rawurlencode($text) : null,
        ];
    }

    private function locationId(Request $request): int
    {
        return CurrentLocation::id() ?? (int) $request->user()->home_location_id;
    }

    /**
     * @return array<int, int>
     */
    private function categoryIds(int $categoryId): array
    {
        $ids = [$categoryId];
        $frontier = [$categoryId];
        for ($depth = 0; $depth < 3 && $frontier !== []; $depth++) {
            $frontier = Category::query()->whereIn('parent_id', $frontier)->pluck('id')->all();
            $ids = [...$ids, ...$frontier];
        }

        return $ids;
    }

    private function discountValue(?string $type, ?string $value): int
    {
        if (! $type || blank($value)) {
            return 0;
        }

        return $type === 'percent' ? BasisPoints::fromPercent($value) : Money::fromDecimal($value)->cents;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function heldCarts(int $locationId): array
    {
        return PosHeldCart::query()
            ->where('location_id', $locationId)
            ->with('user:id,name')
            ->latest('id')
            ->limit(30)
            ->get()
            ->map(fn (PosHeldCart $c) => [
                'id' => $c->id,
                'label' => $c->label,
                'items' => count($c->cart['lines'] ?? []),
                'user' => $c->user?->name,
                'created_at' => $c->created_at?->toIso8601String(),
            ])->all();
    }
}
