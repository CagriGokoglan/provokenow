@extends('emails.layout')

@section('preheader', 'New contact form message from '.$contact->name)
@section('eyebrow', 'Contact form')
@section('headline'){{ $contact->subject }}@endsection

@section('content')
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 20px;font-size:14px;">
<tr><td style="padding:6px 0;color:#8C8079;width:90px;">From</td><td style="padding:6px 0;">{{ $contact->name }}</td></tr>
<tr><td style="padding:6px 0;color:#8C8079;">Email</td><td style="padding:6px 0;"><a href="mailto:{{ $contact->email }}" style="color:#D4AF37;">{{ $contact->email }}</a></td></tr>
<tr><td style="padding:6px 0;color:#8C8079;">Sent</td><td style="padding:6px 0;">{{ $contact->created_at?->toDayDateTimeString() }} UTC</td></tr>
</table>
<p style="margin:0;white-space:pre-line;">{{ $contact->message }}</p>
<p style="margin:24px 0 0;font-size:13px;color:#A99C94;">Reply to this email to answer {{ $contact->name }} directly. The message is also saved in the admin under Legal &amp; Support → Contact messages.</p>
@endsection
