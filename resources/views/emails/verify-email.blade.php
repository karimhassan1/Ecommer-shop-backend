@extends('emails.layout')

@section('title', 'Verify your email')

@section('content')
    <h1 style="margin:0 0 16px 0;font-size:24px;color:#020b44;">Confirm your email</h1>

    <p style="margin:0 0 16px 0;font-size:15px;line-height:24px;color:#4b5563;">
        Hi {{ $customerName }}, please confirm that <strong>{{ $customerName }}</strong>'s account uses this
        email address by clicking the button below.
    </p>

    <table role="presentation" cellpadding="0" cellspacing="0" style="margin:28px 0;">
        <tr>
            <td style="border-radius:10px;background:#020b44;">
                <a href="{{ $verificationUrl }}" style="display:inline-block;padding:14px 28px;color:#ffffff;font-size:15px;font-weight:600;text-decoration:none;">
                    Verify email address
                </a>
            </td>
        </tr>
    </table>

    <p style="margin:0 0 8px 0;font-size:13px;line-height:20px;color:#6b7280;">
        If the button does not work, copy and paste this link into your browser:
    </p>
    <p style="margin:0;font-size:13px;line-height:20px;word-break:break-all;">
        <a href="{{ $verificationUrl }}" style="color:#cd69da;text-decoration:none;">{{ $verificationUrl }}</a>
    </p>
@endsection
