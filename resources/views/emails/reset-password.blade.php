@extends('emails.layout')

@section('preheader', 'A link to choose a new password for your PROVOKE NOW account.')
@section('eyebrow', 'Account security')
@section('headline')Reset Your <em style="color:#D4AF37;">Passcode</em>@endsection

@section('content')
<p style="margin:0 0 16px;">{{ $name ? "Dear {$name}," : 'Hello,' }}</p>
<p style="margin:0;">We received a request to reset the password for your account. Choose a new one below.</p>

@include('emails.partials.button', ['url' => $url, 'label' => 'Choose a new password'])

<p style="margin:0 0 12px;font-size:13px;color:#A99C94;">This link works once and expires in {{ $expires_minutes }} minutes. If the button doesn't open, paste this address into your browser:</p>
<p style="margin:0 0 20px;font-size:12px;word-break:break-all;"><a href="{{ $url }}" style="color:#D4AF37;">{{ $url }}</a></p>
<p style="margin:0;font-size:13px;color:#A99C94;">Didn't ask for this? Ignore this email — your password stays as it is. Your vault passcode is never changed by this link.</p>
@endsection
