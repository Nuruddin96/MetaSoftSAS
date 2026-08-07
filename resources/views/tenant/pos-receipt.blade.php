<!DOCTYPE html>
<html lang="bn">
<head>
    <meta charset="UTF-8">
    <title>রিসিট — {{ $order->order_number }}</title>
    <link href="https://fonts.googleapis.com/css2?family=Hind+Siliguri:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Hind Siliguri', sans-serif; width: 76mm; margin: 0 auto; padding: 4mm; font-size: 12px; color: #000; }
        .center { text-align: center; }
        .bold { font-weight: 700; }
        .row { display: flex; justify-content: space-between; }
        .dashed { border-top: 1px dashed #000; margin: 6px 0; }
        table { width: 100%; border-collapse: collapse; }
        td { padding: 2px 0; vertical-align: top; }
        .toolbar { text-align: center; margin-bottom: 10px; }
        @media print { .toolbar { display: none; } body { padding: 0; } }
    </style>
</head>
<body>
<div class="toolbar"><button onclick="window.print()">🖨️ প্রিন্ট করুন</button></div>

<p class="center bold" style="font-size:15px">{{ $tenant->store_name }}</p>
<p class="center">{{ $tenant->owner_phone }}</p>
<div class="dashed"></div>
<div class="row"><span>রিসিট: {{ $order->order_number }}</span></div>
<div class="row"><span>{{ $order->created_at->format('d/m/Y h:i A') }}</span></div>
@if ($order->customer_name !== 'ওয়াক-ইন কাস্টমার')
    <div class="row"><span>কাস্টমার: {{ $order->customer_name }} {{ $order->customer_phone }}</span></div>
@endif
<div class="dashed"></div>

<table>
    @foreach ($order->items as $item)
        <tr>
            <td>{{ $item->product_name }}@if($item->variant_name && $item->variant_name !== 'Default') ({{ $item->variant_name }})@endif<br>
                <small>{{ $item->quantity }} × {{ number_format($item->unit_price) }}</small></td>
            <td style="text-align:right">{{ number_format($item->line_total) }}৳</td>
        </tr>
    @endforeach
</table>

<div class="dashed"></div>
<div class="row"><span>সাবটোটাল</span><span>{{ number_format($order->subtotal) }}৳</span></div>
@if ($order->discount > 0)
    <div class="row"><span>ডিসকাউন্ট</span><span>−{{ number_format($order->discount) }}৳</span></div>
@endif
<div class="row bold" style="font-size:14px"><span>মোট</span><span>{{ number_format($order->total) }}৳</span></div>
<div class="row"><span>পরিশোধ</span><span>{{ number_format($order->paid_amount) }}৳</span></div>
@if ($order->due_amount > 0)
    <div class="row bold"><span>বাকি</span><span>{{ number_format($order->due_amount) }}৳</span></div>
@endif
<div class="dashed"></div>
<p class="center">ধন্যবাদ! আবার আসবেন 🙏</p>
<p class="center" style="font-size:9px;margin-top:4px">Powered by MetaSoft BD</p>

<script>window.onload = () => setTimeout(() => window.print(), 300);</script>
</body>
</html>
