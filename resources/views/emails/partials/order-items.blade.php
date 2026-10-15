{{-- Item breakdown and totals. --}}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:8px 0 0;font-family:Helvetica,Arial,sans-serif;font-size:14px;color:#E9E1DA;">
@foreach ($items as $item)
<tr>
  <td style="padding:10px 0;border-bottom:1px solid #2E2328;">{{ $item['name'] }} <span style="color:#8C8079;">&times; {{ $item['quantity'] }}</span></td>
  <td align="right" style="padding:10px 0;border-bottom:1px solid #2E2328;white-space:nowrap;">{{ $item['total'] }}</td>
</tr>
@endforeach
@isset($subtotal)
<tr><td style="padding:12px 0 4px;color:#A99C94;">Subtotal</td><td align="right" style="padding:12px 0 4px;color:#A99C94;">{{ $subtotal }}</td></tr>
@if ($discount)<tr><td style="padding:4px 0;color:#A99C94;">Bundle savings</td><td align="right" style="padding:4px 0;color:#A99C94;">&minus;{{ $discount }}</td></tr>@endif
@if ($requires_shipping)<tr><td style="padding:4px 0;color:#A99C94;">Shipping</td><td align="right" style="padding:4px 0;color:#A99C94;">{{ $shipping }}</td></tr>@endif
<tr><td style="padding:4px 0;color:#A99C94;">Tax</td><td align="right" style="padding:4px 0;color:#A99C94;">{{ $tax }}</td></tr>
<tr><td style="padding:12px 0 0;font-size:16px;color:#F7F2EE;"><strong>Total</strong></td><td align="right" style="padding:12px 0 0;font-size:16px;color:#D4AF37;"><strong>{{ $total }}</strong></td></tr>
@endisset
</table>
