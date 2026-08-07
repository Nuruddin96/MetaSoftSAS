@php
    $labels = [];
    foreach ($product->variants as $v) {
        $labels[] = [
            'name'    => $product->name,
            'variant' => $v->variant_name,
            'price'   => number_format($v->selling_price),
            'barcode' => $v->barcode,
        ];
    }
@endphp
<!DOCTYPE html>
<html lang="bn">
<head>
    <meta charset="UTF-8">
    <title>বারকোড — {{ $product->name }}</title>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jsbarcode/3.11.6/JsBarcode.all.min.js"></script>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .toolbar { margin-bottom: 16px; }
        .grid { display: flex; flex-wrap: wrap; gap: 8px; }
        .label { width: 38mm; padding: 2mm; border: 1px dashed #bbb; text-align: center; page-break-inside: avoid; }
        .label p { margin: 1px 0; font-size: 9px; overflow: hidden; white-space: nowrap; text-overflow: ellipsis; }
        .label .price { font-weight: bold; font-size: 11px; }
        svg { width: 100%; height: 14mm; }
        @media print { .toolbar { display: none; } .label { border: none; } }
    </style>
</head>
<body>
<div class="toolbar">
    <label>প্রতিটার কপি: <input type="number" id="copies" value="10" min="1" max="200" style="width:60px"></label>
    <button onclick="render()">রিফ্রেশ</button>
    <button onclick="window.print()">🖨️ প্রিন্ট</button>
    <span style="color:#777;font-size:12px">— A4 স্টিকার শিটে প্রিন্ট করে কেটে প্রোডাক্টের গায়ে লাগান</span>
</div>
<div class="grid" id="sheet"></div>

<script id="labelData" type="application/json">@json($labels)</script>
<script>
    const variants = JSON.parse(document.getElementById('labelData').textContent);

    function render() {
        const copies = parseInt(document.getElementById('copies').value) || 1;
        const sheet = document.getElementById('sheet');
        sheet.innerHTML = '';
        let n = 0;

        variants.forEach(function (v) {
            if (!v.barcode) return;
            for (let i = 0; i < copies; i++) {
                const d = document.createElement('div');
                d.className = 'label';
                const title = v.variant && v.variant !== 'Default' ? v.name + ' — ' + v.variant : v.name;
                d.innerHTML = '<p>' + title + '</p><svg id="bc' + n + '"></svg><p class="price">৳ ' + v.price + '</p>';
                sheet.appendChild(d);
                JsBarcode('#bc' + n, v.barcode, { format: 'CODE128', displayValue: true, fontSize: 10, height: 34, margin: 0 });
                n++;
            }
        });

        if (n === 0) {
            sheet.innerHTML = '<p style="color:#c00">এই প্রোডাক্টের কোনো বারকোড নেই। প্রোডাক্টটি মুছে নতুন করে যোগ করুন।</p>';
        }
    }
    render();
</script>
</body>
</html>
