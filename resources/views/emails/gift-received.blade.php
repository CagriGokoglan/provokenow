@extends('emails.layout')

@section('preheader', $from.' sent you something special.')
@section('eyebrow', 'A gift for you')
@section('headline'){{ $from }} sent you <em style="color:#D4AF37;">a gift</em>.@endsection

@section('content')
@if ($claim_url && $registered)
<p style="margin:0;">You’ve received a special relationship card gift from <strong>{{ $from }}</strong>! Log in to reveal your card in your Vault.</p>
@elseif ($claim_url)
<p style="margin:0;">Someone special (<strong>{{ $from }}</strong>) sent you an exclusive relationship card gift! Click below to claim your gift and set up your Vault.</p>
@else
<p style="margin:0;"><strong>{{ $from }}</strong> sent you a gift. It is on its way to you in plain, discreet packaging with no indication of its contents.</p>
@endif

@if ($note)
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:24px 0 0;">
<tr><td style="padding:18px 20px;border-left:2px solid #D4AF37;background:#1E161B;font-family:Georgia,serif;font-style:italic;font-size:17px;line-height:1.5;color:#F7F2EE;white-space:pre-line;">“{{ $note }}”</td></tr>
</table>
@endif

@if ($claim_url)
@include('emails.partials.button', ['url' => $claim_url, 'label' => $registered ? 'Reveal my gift' : 'Register & Claim My Gift'])
<p style="margin:0;font-size:13px;color:#A99C94;">For adults 18+ only: you’ll be asked to confirm your age before the card is revealed.{{ $expires ? ' Claim it by '.$expires.'.' : '' }}</p>
@if ($ships)
<p style="margin:12px 0 0;font-size:13px;color:#A99C94;">A physical piece is also on its way in plain, unbranded packaging.</p>
@endif
@endif

<p style="margin:24px 0 0;font-size:13px;color:#A99C94;">New to gifting? <a href="{{ $how_url }}" style="color:#D4AF37;">See how it works</a>.</p>
@endsection
