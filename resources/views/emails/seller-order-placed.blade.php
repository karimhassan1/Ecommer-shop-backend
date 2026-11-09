@extends('emails.layout')

@section('title', 'New order')

@section('content')
    <h1 style="margin:0 0 8px 0;font-size:24px;color:#020b44;">You have a new order</h1>

    <p style="margin:0 0 20px 0;font-size:15px;line-height:24px;color:#4b5563;">
        Hi {{ $seller->name }}, order <strong>#{{ $order->id }}</strong> includes
        {{ $lines->count() }} of your product line(s). Please prepare them for dispatch.
    </p>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 24px 0;border:1px solid #ece9f1;border-radius:12px;overflow:hidden;">
        <tr style="background:#f7f5fb;">
            <th align="left" style="padding:12px 16px;font-size:12px;letter-spacing:0.6px;text-transform:uppercase;color:#6b7280;">Your item</th>
            <th align="center" style="padding:12px 16px;font-size:12px;letter-spacing:0.6px;text-transform:uppercase;color:#6b7280;">Qty</th>
            <th align="right" style="padding:12px 16px;font-size:12px;letter-spacing:0.6px;text-transform:uppercase;color:#6b7280;">Total</th>
        </tr>

        @foreach ($lines as $line)
            <tr>
                <td style="padding:12px 16px;font-size:14px;color:#2f2f36;border-top:1px solid #f1eef5;">
                    {{ $line->product?->name ?? 'Product #'.$line->product_id }}
                    @if ($line->variant_label)
                        <div style="font-size:12px;color:#6b7280;">{{ $line->variant_label }}</div>
                    @endif
                </td>
                <td align="center" style="padding:12px 16px;font-size:14px;color:#2f2f36;border-top:1px solid #f1eef5;">
                    {{ $line->quantity }}
                </td>
                <td align="right" style="padding:12px 16px;font-size:14px;color:#2f2f36;border-top:1px solid #f1eef5;">
                    ${{ number_format((float) $line->line_total, 2) }}
                </td>
            </tr>
        @endforeach

        <tr>
            <td colspan="2" align="right" style="padding:14px 16px;font-size:14px;font-weight:600;color:#020b44;border-top:1px solid #ece9f1;">
                Your subtotal
            </td>
            <td align="right" style="padding:14px 16px;font-size:16px;font-weight:700;color:#cd69da;border-top:1px solid #ece9f1;">
                ${{ number_format($sellerTotal, 2) }}
            </td>
        </tr>
    </table>

    <h2 style="margin:0 0 8px 0;font-size:15px;color:#020b44;">Ship to</h2>
    <p style="margin:0 0 24px 0;font-size:14px;line-height:22px;color:#4b5563;">
        {{ $order->name }}<br>
        {{ $order->street }}, {{ $order->city }}<br>
        {{ $order->province }} {{ $order->postal_code }}<br>
        {{ $order->phone }}
    </p>

    <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0;">
        <tr>
            <td style="border-radius:10px;background:#020b44;">
                <a href="{{ $dashboardUrl }}" style="display:inline-block;padding:14px 28px;color:#ffffff;font-size:15px;font-weight:600;text-decoration:none;">
                    Open my orders
                </a>
            </td>
        </tr>
    </table>
@endsection
