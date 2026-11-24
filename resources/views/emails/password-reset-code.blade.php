@extends('emails.layout')

@section('title', 'Your password reset code')

@section('content')
    <h1 style="margin:0 0 16px 0;font-size:24px;color:#020b44;">Reset your password</h1>

    <p style="margin:0 0 20px 0;font-size:15px;line-height:24px;color:#4b5563;">
        Hi {{ $customerName }}, use the code below to choose a new password for your
        {{ config('mail.branding.name') }} account.
    </p>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 24px 0;">
        <tr>
            <td align="center" style="padding:22px 16px;background:#f7f5fb;border:1px solid #ece9f1;border-radius:12px;">
                <div style="font-size:13px;letter-spacing:0.14em;text-transform:uppercase;color:#6b7280;margin-bottom:10px;">
                    Password reset code
                </div>
                <div style="font-family:'Courier New',Courier,monospace;font-size:34px;font-weight:700;letter-spacing:0.34em;color:#020b44;">
                    {{ $code }}
                </div>
            </td>
        </tr>
    </table>

    <p style="margin:0 0 16px 0;font-size:14px;line-height:22px;color:#4b5563;">
        Enter this code on the reset page, pick a new password and you are done.
        The code expires in <strong>{{ $expiresInMinutes }} minutes</strong> and can
        only be used once.
    </p>

    <p style="margin:0;font-size:13px;line-height:20px;color:#9aa0ac;">
        Didn't request a password reset? No action is needed, your password stays unchanged.
    </p>
@endsection
