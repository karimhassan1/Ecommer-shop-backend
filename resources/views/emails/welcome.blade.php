@extends('emails.layout')

@section('title', 'Welcome')

@section('content')
    <h1 style="margin:0 0 16px 0;font-size:24px;color:#020b44;">Welcome aboard, {{ $customerName }} 🎉</h1>

    <p style="margin:0 0 16px 0;font-size:15px;line-height:24px;color:#4b5563;">
        Thanks for creating an account at {{ config('mail.branding.name') }}. Your account is ready —
        you can now save designs, track orders and check out faster.
    </p>

    <table role="presentation" cellpadding="0" cellspacing="0" style="margin:28px 0;">
        <tr>
            <td style="border-radius:10px;background:#cd69da;">
                <a href="{{ $shopUrl }}" style="display:inline-block;padding:14px 28px;color:#ffffff;font-size:15px;font-weight:600;text-decoration:none;">
                    Start shopping
                </a>
            </td>
        </tr>
    </table>

    <p style="margin:0;font-size:14px;line-height:22px;color:#6b7280;">
        If you did not create this account you can safely ignore this email.
    </p>
@endsection
