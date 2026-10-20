@extends('emails.layout')

@section('preheader', 'Your sign-in verification code.')
@section('eyebrow', 'Security')
@section('headline')Your verification <em style="color:#D4AF37;">code</em>@endsection

@section('content')
<p style="margin:0;">Someone (hopefully you) is signing in and asked for a code by email instead of the authenticator app. Enter this code on the sign-in screen:</p>
<p style="margin:28px 0;text-align:center;font-family:Menlo,Consolas,monospace;font-size:34px;letter-spacing:10px;color:#F7F2EE;">{{ $code }}</p>
<p style="margin:0;font-size:13px;color:#A99C94;">It expires in {{ $minutes }} minutes and works once. If this wasn’t you, your password is known to someone else: change it now and review your account.</p>
@endsection
