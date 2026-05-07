<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>{{ $subjectLine }}</title>
</head>
<body style="margin:0;padding:0;background:#f5f5f5;font-family:Arial,Helvetica,sans-serif;color:#171717;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f5f5f5;padding:24px 0;">
    <tr>
        <td align="center">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:640px;background:#ffffff;border:1px solid #e5e5e5;">
                <tr>
                    <td style="padding:32px 32px 16px 32px;">
                        <p style="margin:0 0 8px 0;font-size:12px;letter-spacing:0.08em;text-transform:uppercase;color:#737373;">
                            {{ config('app.name', 'FullParty') }}
                        </p>
                        <h1 style="margin:0;font-size:24px;line-height:1.3;color:#171717;">
                            {{ $subjectLine }}
                        </h1>
                    </td>
                </tr>
                @if (filled($bodyText))
                    <tr>
                        <td style="padding:0 32px 24px 32px;">
                            <p style="margin:0;font-size:15px;line-height:1.7;color:#404040;">
                                {!! nl2br(e($bodyText)) !!}
                            </p>
                        </td>
                    </tr>
                @endif
                @if (filled($actionUrl))
                    <tr>
                        <td style="padding:0 32px 32px 32px;">
                            <a
                                href="{{ $actionUrl }}"
                                style="display:inline-block;background:#70439b;color:#ffffff;text-decoration:none;font-size:14px;font-weight:600;padding:12px 18px;border-radius:2px;"
                            >
                                {{ __('email.labels.open') }} {{ config('app.name', 'FullParty') }}
                            </a>
                        </td>
                    </tr>
                @endif
            </table>
        </td>
    </tr>
</table>
</body>
</html>
