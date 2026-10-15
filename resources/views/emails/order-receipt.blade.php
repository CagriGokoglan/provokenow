@extends('emails.layout')

@section('preheader', "Order {$order_number} is confirmed.")
@section('eyebrow', 'Order '.$order_number)
@section('headline')Thank you, <em style="color:#D4AF37;">truly</em>.@endsection

@section('content')
<p style="margin:0 0 16px;">{{ $customer_name ? "Dear {$customer_name}," : 'Hello,' }}</p>
<p style="margin:0;">Your order is confirmed{{ count($decks) && $requires_shipping ? ': your decks are unlocked and your parcel is being prepared.' : (count($decks) ? ' and your decks are unlocked.' : ' and is being prepared for dispatch.') }}</p>

@if (count($decks))
<p style="margin:30px 0 8px;font-family:Helvetica,Arial,sans-serif;font-size:11px;letter-spacing:3px;text-transform:uppercase;color:#D4AF37;">Your decks are ready</p>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0">
@foreach ($decks as $deck)
<tr><td style="padding:10px 0;border-bottom:1px solid #2E2328;">
  <span style="font-family:Helvetica,Arial,sans-serif;font-size:11px;color:#8C8079;letter-spacing:1px;">{{ $deck['code'] }}</span><br>
  <a href="{{ $deck['url'] }}" style="color:#F7F2EE;text-decoration:none;font-size:17px;">{{ $deck['title'] }} <span style="color:#D4AF37;">&rarr;</span></a>
</td></tr>
@endforeach
</table>
@include('emails.partials.button', ['url' => $library_url, 'label' => 'Open my library'])
@endif

<p style="margin:{{ count($decks) ? '6px' : '30px' }} 0 4px;font-family:Helvetica,Arial,sans-serif;font-size:11px;letter-spacing:3px;text-transform:uppercase;color:#D4AF37;">Order summary</p>
@include('emails.partials.order-items')

@if ($requires_shipping)
@include('emails.partials.address')
@include('emails.partials.discreet-badge')
<p style="margin:20px 0 0;font-size:13px;color:#A99C94;">We'll email you the tracking details as soon as it ships.</p>
@endif
@endsection
