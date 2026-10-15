@extends('emails.layout')

@section('preheader', 'Confirm your email address to finish setting up your PROVOKE NOW account.')
@section('eyebrow', 'Welcome')
@section('headline')Verify Your <em style="color:#D4AF37;">Desire</em>@endsection

@section('content')
<p style="margin:0 0 16px;">{{ $name ? "Dear {$name}," : 'Hello,' }}</p>
<p style="margin:0;">One small step before the cards are dealt: confirm that this address is yours.</p>

@include('emails.partials.button', ['url' => $url, 'label' => 'Verify my email'])

<p style="margin:0 0 12px;font-size:13px;color:#A99C94;">The link stays valid for {{ $expires_hours }} hours. If the button doesn't open, paste this address into your browser:</p>
<p style="margin:0 0 20px;font-size:12px;word-break:break-all;"><a href="{{ $url }}" style="color:#D4AF37;">{{ $url }}</a></p>
<p style="margin:0;font-size:13px;color:#A99C94;">Didn't create an account? You can safely ignore this email.</p>
@endsection
