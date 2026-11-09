@php
    $brand = config('mail.branding.name');
    $support = config('mail.branding.support_email');
    $frontend = rtrim(config('mail.branding.frontend_url'), '/');
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', $brand)</title>
</head>
<body style="margin:0;padding:0;background:#f5f3f7;font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;color:#2f2f36;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f5f3f7;padding:32px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 12px 32px rgba(2,11,68,0.08);">
                    <!-- Header -->
                    <tr>
                        <td style="background:#020b44;padding:28px 32px;text-align:center;">
                            <a href="{{ $frontend }}" style="color:#ffffff;text-decoration:none;font-size:22px;font-weight:700;letter-spacing:0.5px;">
                                {{ $brand }}
                            </a>
                        </td>
                    </tr>

                    <!-- Body -->
                    <tr>
                        <td style="padding:36px 32px 8px 32px;">
                            @yield('content')
                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td style="padding:24px 32px 32px 32px;border-top:1px solid #ece9f1;">
                            <p style="margin:0 0 6px 0;font-size:13px;color:#6b7280;">
                                Need help? Write to
                                <a href="mailto:{{ $support }}" style="color:#cd69da;text-decoration:none;">{{ $support }}</a>
                            </p>
                            <p style="margin:0;font-size:12px;color:#9aa0ac;">
                                &copy; {{ date('Y') }} {{ $brand }}. All rights reserved.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
