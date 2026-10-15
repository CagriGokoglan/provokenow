@if (count($address_lines))
<p style="margin:26px 0 6px;font-family:Helvetica,Arial,sans-serif;font-size:11px;letter-spacing:3px;text-transform:uppercase;color:#D4AF37;">Delivering to</p>
<p style="margin:0;font-size:14px;line-height:1.6;color:#E9E1DA;">{!! collect($address_lines)->map(fn ($l) => e($l))->implode('<br>') !!}</p>
@endif
