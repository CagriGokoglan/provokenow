@extends('emails.layout')

@section('content')
<p>{{ $customer_name ? "Dear {$customer_name}," : 'Hello,' }}</p>
@if ($status === 'delivered')
<p>Your order <strong>{{ $order_number }}</strong> has been delivered. We hope you enjoy it.</p>
@else
<p>Your order <strong>{{ $order_number }}</strong> is on its way.</p>
@endif

@if ($tracking_number)
<p>Tracking number: <strong>{{ $tracking_number }}</strong>
@if ($tracking_url)<br><a href="{{ $tracking_url }}" style="color:#c9a36a;">Track your parcel &rarr;</a>@endif
</p>
@endif
@endsection
