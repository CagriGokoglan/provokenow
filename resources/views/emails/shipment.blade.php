@extends('emails.layout')

@if ($delivered)
@section('preheader', "Order {$order_number} has been delivered.")
@section('eyebrow', 'Delivered')
@section('headline')It has <em style="color:#D4AF37;">arrived</em>.@endsection
@else
@section('preheader', "Order {$order_number} is on its way.")
@section('eyebrow', 'Shipped')
@section('headline')On its <em style="color:#D4AF37;">way</em>.@endsection
@endif

@section('content')
<p style="margin:0 0 16px;">{{ $customer_name ? "Dear {$customer_name}," : 'Hello,' }}</p>
@if ($delivered)
<p style="margin:0;">Your order <strong>{{ $order_number }}</strong> has been delivered. We hope it makes for unforgettable evenings.</p>
@else
<p style="margin:0;">Your order <strong>{{ $order_number }}</strong> left our studio on {{ $shipped_on }}.</p>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:26px 0 0;font-family:Helvetica,Arial,sans-serif;font-size:14px;color:#E9E1DA;">
@if ($courier)
<tr><td style="padding:8px 0;color:#8C8079;width:42%;">Courier</td><td style="padding:8px 0;">{{ $courier }}</td></tr>
@endif
@if ($tracking_number)
<tr><td style="padding:8px 0;color:#8C8079;">Tracking number</td><td style="padding:8px 0;font-family:'Courier New',monospace;letter-spacing:1px;">{{ $tracking_number }}</td></tr>
@endif
<tr><td style="padding:8px 0;color:#8C8079;">{{ $estimate_is_date ? 'Expected by' : 'Expected in' }}</td><td style="padding:8px 0;color:#D4AF37;">{{ $estimate }}</td></tr>
</table>

@if ($tracking_url)
@include('emails.partials.button', ['url' => $tracking_url, 'label' => 'Track my parcel'])
@endif
@endif

<p style="margin:{{ ! $delivered && $tracking_url ? '0' : '26px' }} 0 4px;font-family:Helvetica,Arial,sans-serif;font-size:11px;letter-spacing:3px;text-transform:uppercase;color:#D4AF37;">In this parcel</p>
@include('emails.partials.order-items', ['items' => array_values(array_filter($items, fn ($i) => $i['ships'])), 'subtotal' => null])

@unless ($delivered)
@include('emails.partials.address')
@include('emails.partials.discreet-badge')
@endunless

<p style="margin:22px 0 0;font-size:13px;color:#A99C94;">Your order history is always in <a href="{{ $orders_url }}" style="color:#D4AF37;">your account</a>.</p>
@endsection
