<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Your Reminder System digest</title></head>
<body style="margin:0;padding:0;background-color:#f3f4f6;font-family:Arial,Helvetica,sans-serif;color:#111827;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f3f4f6;padding:24px 12px;">
  <tr><td align="center">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:640px;background:#fff;border:1px solid #e5e7eb;border-radius:8px;">
      <tr><td style="background:#0f172a;color:#fff;padding:18px 24px;font-size:18px;font-weight:bold;">TechRon Reminder System</td></tr>
      <tr><td style="padding:24px;">
        <p style="margin:0 0 16px;font-size:16px;line-height:1.5;">Hello {{ $greetingName }},</p>
        <p style="margin:0 0 20px;font-size:15px;line-height:1.6;color:#374151;">Here are your items due today and coming up:</p>
        @foreach($groups as $label => $group)
          @if(count($group) > 0)
            <p style="margin:20px 0 8px;font-size:16px;font-weight:bold;">{{ $label }}</p>
            <table role="presentation" width="100%" cellpadding="8" cellspacing="0" border="0" style="border-collapse:collapse;font-size:14px;">
              <tr style="background:#f9fafb;text-align:left;"><th style="border-bottom:1px solid #e5e7eb;">Task / item</th><th style="border-bottom:1px solid #e5e7eb;">Client</th><th style="border-bottom:1px solid #e5e7eb;">Due</th><th style="border-bottom:1px solid #e5e7eb;">Days left</th></tr>
              @foreach($group as $entry)
                <tr><td style="border-bottom:1px solid #e5e7eb;"><a href="{{ $entry['url'] }}" style="color:#2563eb;">{{ $entry['title'] }}</a></td><td style="border-bottom:1px solid #e5e7eb;">{{ $entry['client'] }}</td><td style="border-bottom:1px solid #e5e7eb;">{{ $entry['due_date'] }}</td><td style="border-bottom:1px solid #e5e7eb;">{{ $entry['days_remaining'] }}</td></tr>
              @endforeach
            </table>
          @endif
        @endforeach
      </td></tr>
      <tr><td style="padding:16px 24px;background:#f9fafb;border-top:1px solid #e5e7eb;color:#6b7280;font-size:12px;line-height:1.6;">You received this because you have a Reminder System account. You can choose which emails you get in My profile.</td></tr>
    </table>
  </td></tr>
</table>
</body>
</html>
