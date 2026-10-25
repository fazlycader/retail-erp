<div class="box">
    <div class="label">{{ $heading ?? 'Customer' }}</div>
    @if ($customer)
        <div class="bold">{{ $customer->name }}</div>
        <div>{{ $customer->displayPhone() }}</div>
        @if ($customer->vat_number)<div>VAT No: {{ $customer->vat_number }}</div>@endif
        @php $address = $address ?? $customer->defaultAddress(); @endphp
        @if (is_array($address))
            <div>{{ collect([$address['line1'] ?? null, $address['line2'] ?? null, $address['city'] ?? null, $address['district'] ?? null])->filter()->implode(', ') }}</div>
        @endif
    @else
        <div class="bold">Walk-in customer</div>
    @endif
</div>
