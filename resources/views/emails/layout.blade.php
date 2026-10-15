<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="dark">
<meta name="supported-color-schemes" content="dark">
<title>PROVOKE NOW</title>
</head>
<body style="margin:0;padding:0;background:#0D0D0D;color:#F7F2EE;font-family:Georgia,'Times New Roman',serif;-webkit-text-size-adjust:100%;">
@hasSection('preheader')
<div style="display:none;max-height:0;overflow:hidden;opacity:0;">@yield('preheader')</div>
@endif
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#0D0D0D;">
<tr><td align="center" style="padding:40px 16px;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;">
    {{-- Wordmark (live text, so it shows even with images blocked) --}}
    <tr><td align="center" style="padding-bottom:28px;">
      <span style="font-size:22px;letter-spacing:7px;color:#F7F2EE;">PROVOKE</span>
      <span style="font-size:20px;font-style:italic;color:#D4AF37;">&nbsp;now</span>
    </td></tr>

    <tr><td style="background:#141012;border:1px solid #2E2328;border-radius:18px;padding:44px 36px;">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
        @hasSection('eyebrow')
        <tr><td style="font-family:Helvetica,Arial,sans-serif;font-size:11px;letter-spacing:4px;text-transform:uppercase;color:#D4AF37;padding-bottom:14px;">@yield('eyebrow')</td></tr>
        @endif
        @hasSection('headline')
        <tr><td style="font-size:34px;line-height:1.15;font-weight:normal;color:#F7F2EE;padding-bottom:22px;">@yield('headline')</td></tr>
        @endif
        <tr><td style="font-size:16px;line-height:1.75;color:#E9E1DA;">@yield('content')</td></tr>
      </table>
    </td></tr>

    <tr><td style="padding:28px 8px 0;font-family:Helvetica,Arial,sans-serif;font-size:12px;line-height:1.7;color:#8C8079;text-align:center;">
      @if (! empty($support_email))
      Questions? Reply to this email or write to <a href="mailto:{{ $support_email }}" style="color:#D4AF37;text-decoration:none;">{{ $support_email }}</a>.<br>
      @endif
      <span style="display:inline-block;margin-top:12px;border:1px solid #6E2A35;border-radius:999px;padding:1px 8px;color:#C84B5B;font-weight:bold;letter-spacing:1px;">18+</span>
      &nbsp;PROVOKE NOW is intended for adults aged 18 and over. Subject lines and sender details are kept discreet.<br>
      &copy; {{ date('Y') }} PROVOKE NOW &middot; Consent first, always.
    </td></tr>
  </table>
</td></tr>
</table>
</body>
</html>
