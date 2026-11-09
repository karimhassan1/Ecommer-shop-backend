@extends('emails.layout')

@section('title', 'New contact message')

@section('content')
    <h1 style="margin:0 0 16px 0;font-size:22px;color:#020b44;">New contact message</h1>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 20px 0;font-size:14px;color:#2f2f36;">
        <tr>
            <td style="padding:6px 0;color:#6b7280;width:90px;">From</td>
            <td style="padding:6px 0;">{{ $contact->name }}</td>
        </tr>
        <tr>
            <td style="padding:6px 0;color:#6b7280;">Email</td>
            <td style="padding:6px 0;">
                <a href="mailto:{{ $contact->email }}" style="color:#cd69da;text-decoration:none;">{{ $contact->email }}</a>
            </td>
        </tr>
        <tr>
            <td style="padding:6px 0;color:#6b7280;">Subject</td>
            <td style="padding:6px 0;">{{ $contact->subject }}</td>
        </tr>
        <tr>
            <td style="padding:6px 0;color:#6b7280;">Received</td>
            <td style="padding:6px 0;">{{ $contact->created_at?->toDayDateTimeString() }}</td>
        </tr>
    </table>

    <div style="padding:18px;background:#f7f5fb;border-radius:12px;font-size:14px;line-height:22px;color:#2f2f36;white-space:pre-line;">
        {{ $contact->message }}
    </div>
@endsection
