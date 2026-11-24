@extends('emails.layout')

@section('title', 'Order confirmed')

@section('content')
    <h1 style="margin:0 0 8px 0;font-size:24px;color:#020b44;">Thank you for your order!</h1>

    <p style="margin:0 0 20px 0;font-size:15px;line-height:24px;color:#4b5563;">
        Order <strong>#{{ $order->id }}</strong> is confirmed and we are getting it ready.
    </p>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 24px 0;border:1px solid #ece9f1;border-radius:12px;overflow:hidden;">
        <tr style="background:#f7f5fb;">
            <th align="left" style="padding:12px 16px;font-size:12px;letter-spacing:0.6px;text-transform:uppercase;color:#6b7280;">Item</th>
            <th align="center" style="padding:12px 16px;font-size:12px;letter-spacing:0.6px;text-transform:uppercase;color:#6b7280;">Qty</th>
            <th align="right" style="padding:12px 16px;font-size:12px;letter-spacing:0.6px;text-transform:uppercase;color:#6b7280;">Total</th>
        </tr>

        @foreach ($lines as $line)
            <tr>
                <td style="padding:12px 16px;font-size:14px;color:#2f2f36;border-top:1px solid #f1eef5;">
                    {{-- The snapshot taken at checkout, so a later rename cannot rewrite this receipt. --}}
                    {{ $line->display_name }}
                    @if ($line->variant_label)
                        <div style="font-size:12px;color:#6b7280;">{{ $line->variant_label }}</div>
                    @endif
                    @if ($line->sku)
                        <div style="font-size:11px;color:#9aa0ac;">SKU {{ $line->sku }}</div>
                    @endif
                    @if ((float) $line->discount_value > 0)
                        <div style="font-size:12px;color:#cd69da;">
                            {{ number_format((float) $line->list_price_value, 2) }} each,
                            you saved {{ number_format((float) $line->savings, 2) }}
                        </div>
                    @endif
                </td>
                <td align="center" style="padding:12px 16px;font-size:14px;color:#2f2f36;border-top:1px solid #f1eef5;">
                    {{ $line->quantity }}
                </td>
                <td align="right" style="padding:12px 16px;font-size:14px;color:#2f2f36;border-top:1px solid #f1eef5;">
                    {{ number_format((float) $line->line_total, 2) }}
                </td>
            </tr>
        @endforeach

        <tr>
            <td colspan="2" align="right" style="padding:14px 16px;font-size:14px;font-weight:600;color:#020b44;border-top:1px solid #ece9f1;">
                Total
            </td>
            <td align="right" style="padding:14px 16px;font-size:16px;font-weight:700;color:#cd69da;border-top:1px solid #ece9f1;">
                {{ number_format((float) $order->total_amount, 2) }}
            </td>
        </tr>
    </table>

    <h2 style="margin:0 0 8px 0;font-size:15px;color:#020b44;">Shipping to</h2>
    <p style="margin:0 0 24px 0;font-size:14px;line-height:22px;color:#4b5563;">
        {{ $order->name }}<br>
        {{ $order->street }}, {{ $order->city }}<br>
        {{ $order->province }} {{ $order->postal_code }}<br>
        {{ $order->phone }}
    </p>

    <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 8px 0;">
        <tr>
            <td style="border-radius:10px;background:#020b44;">
                <a href="{{ $accountUrl }}" style="display:inline-block;padding:14px 28px;color:#ffffff;font-size:15px;font-weight:600;text-decoration:none;">
                    Track my order
                </a>
            </td>
        </tr>
    </table>
@endsection
