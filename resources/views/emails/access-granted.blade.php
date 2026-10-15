@extends('emails.layout')

@section('preheader', 'New decks have been added to your PROVOKE NOW library.')
@section('eyebrow', 'Your library')
@section('headline')Something new to <em style="color:#D4AF37;">explore</em>.@endsection

@section('content')
<p style="margin:0 0 16px;">{{ $name ? "Dear {$name}," : 'Hello,' }}</p>
<p style="margin:0;">{{ count($decks) === 1 ? 'A new deck has' : count($decks).' new decks have' }} been added to your library, ready whenever you are.</p>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:22px 0 0;">
@foreach ($decks as $deck)
<tr><td style="padding:10px 0;border-bottom:1px solid #2E2328;">
  <span style="font-family:Helvetica,Arial,sans-serif;font-size:11px;color:#8C8079;letter-spacing:1px;">{{ $deck['code'] }}</span><br>
  <a href="{{ $deck['url'] }}" style="color:#F7F2EE;text-decoration:none;font-size:17px;">{{ $deck['title'] }} <span style="color:#D4AF37;">&rarr;</span></a>
</td></tr>
@endforeach
</table>

@include('emails.partials.button', ['url' => $library_url, 'label' => 'Open my library'])
<p style="margin:0;font-size:13px;color:#A99C94;">Sign in with this email address to open them on any device.</p>
@endsection
