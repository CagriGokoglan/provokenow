@extends('emails.layout')

@section('content')
<p>{{ $customer_name ? "Dear {$customer_name}," : 'Hello,' }}</p>
<p>Thank you. Your order <strong>{{ $order_number }}</strong> is confirmed.</p>

@if (count($deck_access))
<p style="color:#c9a36a;margin-top:28px;">Your decks are ready</p>
@foreach ($deck_access as $deck)
<p><a href="{{ $deck['url'] }}" style="color:#f3ece4;">{{ $deck['title'] }} &rarr;</a></p>
@endforeach
<p style="font-size:13px;color:#8c8079;">Sign in to your account to open them anytime from <a href="{{ $library_url }}" style="color:#c9a36a;">your library</a>.</p>
@endif

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:28px;font-size:14px;">
@foreach ($items as $item)
<tr><td style="padding:6px 0;">{{ $item['name'] }} &times; {{ $item['quantity'] }}</td><td align="right">{{ $item['total'] }}</td></tr>
@endforeach
<tr><td style="padding-top:12px;border-top:1px solid #3a2f2a;">Subtotal</td><td align="right" style="padding-top:12px;border-top:1px solid #3a2f2a;">{{ $subtotal }}</td></tr>
@if ($discount)<tr><td>Bundle savings</td><td align="right">&minus;{{ $discount }}</td></tr>@endif
<tr><td>Shipping</td><td align="right">{{ $shipping }}</td></tr>
<tr><td>Tax</td><td align="right">{{ $tax }}</td></tr>
<tr><td style="padding-top:8px;"><strong>Total</strong></td><td align="right" style="padding-top:8px;"><strong>{{ $total }}</strong></td></tr>
</table>
@endsection
