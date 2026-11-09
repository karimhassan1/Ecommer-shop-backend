@extends('emails.layout')

@section('title', 'Order update')

@section('content')
    <h1 style="margin:0 0 16px 0;font-size:24px;color:#020b44;">Your order is now {{ $statusLabel }}</h1>

    <p style="margin:0 0 20px 0;font-size:15px;line-height:24px;color:#4b5563;">
        Hi {{ $order->name }}, the status of order <strong>#{{ $order->id }}</strong> changed
        from {{ config('shop.order_statuses.'.$previousStatus, $previousStatus) }}
        to <strong>{{ $statusLabel }}</strong>.
    </p>

    @if ($order->tracking_number)
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 24px 0;background:#f7f5fb;border-radius:12px;">
            <tr>
                <td style="padding:16px;font-size:14px;color:#2f2f36;">
                    Tracking number:
                    <strong>{{ $order->tracking_number }}</strong>
                </td>
            </tr>
        </table>
    @endif

    <p style="margin:0 0 20px 0;font-size:15px;line-height:24px;color:#4b5563;">
        Order total: <strong>${{ number_format((float) $order->total_amount, 2) }}</strong>
    </p>

    <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0;">
        <tr>
            <td style="border-radius:10px;background:#cd69da;">
                <a href="{{ $accountUrl }}" style="display:inline-block;padding:14px 28px;color:#ffffff;font-size:15px;font-weight:600;text-decoration:none;">
                    View order details
                </a>
            </td>
        </tr>
    </table>
@endsection
