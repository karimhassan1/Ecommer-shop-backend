@extends('emails.layout')

@section('title', 'Order update')

@section('content')
    <h1 style="margin:0 0 16px 0;font-size:24px;color:#020b44;">Order #{{ $order->id }} is now {{ $order->status_label }}</h1>

    <p style="margin:0 0 20px 0;font-size:15px;line-height:24px;color:#4b5563;">
        Hi {{ $seller->name }}, an order containing your products moved from
        {{ $previousLabel }} to <strong>{{ $order->status_label }}</strong>.
    </p>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 24px 0;border:1px solid #ece9f1;border-radius:12px;overflow:hidden;">
        <tr style="background:#f7f5fb;">
            <th align="left" style="padding:12px 16px;font-size:12px;letter-spacing:0.6px;text-transform:uppercase;color:#6b7280;">Your item</th>
            <th align="center" style="padding:12px 16px;font-size:12px;letter-spacing:0.6px;text-transform:uppercase;color:#6b7280;">Qty</th>
        </tr>

        @foreach ($lines as $line)
            <tr>
                <td style="padding:12px 16px;font-size:14px;color:#2f2f36;border-top:1px solid #f1eef5;">
                    {{ $line->display_name }}
                    @if ($line->variant_label)
                        <div style="font-size:12px;color:#6b7280;">{{ $line->variant_label }}</div>
                    @endif
                    @if ($line->sku)
                        <div style="font-size:11px;color:#9aa0ac;">SKU {{ $line->sku }}</div>
                    @endif
                </td>
                <td align="center" style="padding:12px 16px;font-size:14px;color:#2f2f36;border-top:1px solid #f1eef5;">
                    {{ $line->quantity }}
                </td>
            </tr>
        @endforeach
    </table>

    @if ($order->tracking_number)
        <p style="margin:0 0 20px 0;font-size:14px;color:#2f2f36;">
            Tracking number: <strong>{{ $order->tracking_number }}</strong>
        </p>
    @endif

    <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0;">
        <tr>
            <td style="border-radius:10px;background:#cd69da;">
                <a href="{{ $dashboardUrl }}" style="display:inline-block;padding:14px 28px;color:#ffffff;font-size:15px;font-weight:600;text-decoration:none;">
                    Open my orders
                </a>
            </td>
        </tr>
    </table>
@endsection
