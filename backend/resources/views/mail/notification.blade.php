<?php /** @var string $greetingName */ /** @var string $titleText */ /** @var string $messageText */ /** @var string|null $actionUrl */ /** @var string $buttonLabel */ ?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>{{ $titleText }}</title>
</head>
<body style="margin:0;padding:0;background-color:#f3f4f6;font-family:Arial,Helvetica,sans-serif;color:#111827;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f3f4f6;margin:0;padding:24px 12px;">
    <tr>
      <td align="center">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;background-color:#ffffff;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;">
          <tr>
            <td style="background-color:#0f172a;color:#ffffff;padding:18px 24px;font-size:18px;font-weight:bold;letter-spacing:0.2px;">
              TechRon Reminder System
            </td>
          </tr>
          <tr>
            <td style="padding:24px;">
              <p style="margin:0 0 16px 0;font-size:16px;line-height:1.5;">Hello {{ $greetingName }},</p>
              <p style="margin:0 0 12px 0;font-size:18px;line-height:1.4;font-weight:bold;color:#111827;">{{ $titleText }}</p>
              <p style="margin:0 0 20px 0;font-size:15px;line-height:1.6;color:#374151;white-space:pre-line;">{{ $messageText }}</p>
              @if(!empty($actionUrl))
                <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 20px 0;">
                  <tr>
                    <td align="center" bgcolor="#2563eb" style="border-radius:6px;">
                      <a href="{{ $actionUrl }}" style="display:inline-block;padding:12px 20px;color:#ffffff;text-decoration:none;font-size:15px;font-weight:bold;background-color:#2563eb;border-radius:6px;">{{ $buttonLabel }}</a>
                    </td>
                  </tr>
                </table>
              @endif
              <p style="margin:0;font-size:13px;line-height:1.6;color:#6b7280;">If the button does not work, copy and paste this link into your browser:<br>
                @if(!empty($actionUrl))
                  <a href="{{ $actionUrl }}" style="color:#2563eb;text-decoration:underline;word-break:break-all;">{{ $actionUrl }}</a>
                @endif
              </p>
            </td>
          </tr>
          <tr>
            <td style="padding:16px 24px;background-color:#f9fafb;border-top:1px solid #e5e7eb;color:#6b7280;font-size:12px;line-height:1.6;">
              You received this because you have a Reminder System account. You can choose which emails you get in My profile.
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
