@extends('emails.layout')

@section('title', 'Reset your password')

@section('content')
    <h1 style="margin:0 0 16px 0;font-size:24px;color:#020b44;">Reset your password</h1>

    <p style="margin:0 0 16px 0;font-size:15px;line-height:24px;color:#4b5563;">
        Hi {{ $customerName }}, we received a request to reset the password for your
        {{ config('mail.branding.name') }} account. This link expires in
        <strong>{{ $expiresInMinutes }} minutes</strong>.
    </p>

    <table role="presentation" cellpadding="0" cellspacing="0" style="margin:28px 0;">
        <tr>
            <td style="border-radius:10px;background:#cd69da;">
                <a href="{{ $resetUrl }}" style="display:inline-block;padding:14px 28px;color:#ffffff;font-size:15px;font-weight:600;text-decoration:none;">
                    Choose a new password
                </a>
            </td>
        </tr>
    </table>

    <p style="margin:0 0 8px 0;font-size:13px;line-height:20px;color:#6b7280;">
        If the button does not work, copy and paste this link into your browser:
    </p>
    <p style="margin:0 0 20px 0;font-size:13px;line-height:20px;word-break:break-all;">
        <a href="{{ $resetUrl }}" style="color:#cd69da;text-decoration:none;">{{ $resetUrl }}</a>
    </p>

    <p style="margin:0;font-size:13px;line-height:20px;color:#9aa0ac;">
        Didn't request a password reset? No action is needed, your password stays unchanged.
    </p>
@endsection
