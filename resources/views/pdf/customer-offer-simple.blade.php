<!DOCTYPE html>
<html lang="{{ $documentLocale }}">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: inter, sans-serif; font-size: 9pt; color: #252328; line-height: 1.35; }
        table { width: 100%; border-collapse: collapse; }
        .logo { text-align: center; margin-bottom: 10mm; }
        .title { font-size: 12pt; font-weight: bold; margin-bottom: 8mm; }
        .parties { margin-bottom: 8mm; }
        .parties td { width: 50%; vertical-align: top; padding: 0 5mm 0 0; }
        .party-label { font-size: 9pt; font-weight: bold; color: #74747A; margin-bottom: 2mm; }
        .party-name { font-weight: bold; margin-bottom: 2.5mm; }
        .party-line { color: #74747A; margin-bottom: 1mm; }
        .products { table-layout: fixed; }
        .products th { background-color: #C00000; color: #FFFFFF; font-size: 8.5pt; font-weight: bold; }
        .products th, .products td { border: 0.3mm solid #E2E2E2; padding: 2mm 1.5mm; text-align: center; vertical-align: middle; }
        .products td { font-size: 10pt; }
        .products .product { text-align: left; font-weight: bold; }
        .products .index { color: #CF2029; }
        .products .as-ordered { font-size: 8.5pt; }
        .note { font-size: 10pt; font-style: italic; color: #666666; margin-top: 5mm; }
        .additional-note { font-size: 9pt; color: #666666; margin-top: 2mm; }
        .contacts { margin-top: 10mm; background-color: #C00000; color: #FFFFFF; }
        .contacts td { width: 33.33%; padding: 2mm 1mm; font-size: 9pt; text-align: center; }
    </style>
</head>
<body>
    <htmlpagefooter name="simpleOfferFooter">
        <div style="text-align: center; color: #74747A; font-size: 8pt;">{PAGENO} / {nbpg}</div>
    </htmlpagefooter>
    <sethtmlpagefooter name="simpleOfferFooter" value="on" />

    <div class="logo"><img src="{{ $simpleLogo }}" style="width: 95mm;" /></div>
    <div class="title">{{ strtr($labels['title'], [':number' => $offerNumber, ':date' => $offerDate]) }}</div>

    <table class="parties">
        <tr>
            <td>
                <div class="party-label">{{ mb_strtoupper($labels['supplier']) }}</div>
                <div class="party-name">{{ mb_strtoupper($supplier['name']) }}</div>
                <div class="party-line">{{ $labels['taxId'] }}: {{ $supplier['cif'] }}</div>
                <div class="party-line">{{ $labels['registration'] }}: {{ $supplier['reg'] }}</div>
                <div class="party-line">{{ $supplier['address'] }}</div>
            </td>
            <td>
                <div class="party-label">{{ mb_strtoupper($labels['buyer']) }}</div>
                <div class="party-name">{{ mb_strtoupper($buyer['name']) }}</div>
                <div class="party-line">{{ $labels['taxId'] }}: {{ $buyer['cif'] }}</div>
                <div class="party-line">{{ $labels['registration'] }}: {{ $buyer['reg'] }}</div>
                <div class="party-line">{{ $labels['locality'] }}: {{ $buyer['locality'] }}</div>
                <div class="party-line">{{ $labels['contact'] }}: {{ $buyer['phone'] }} / {{ $buyer['email'] }}</div>
            </td>
        </tr>
    </table>

    <table class="products">
        <thead>
            <tr>
                <th style="width: 6%;">{{ $labels['number'] }}</th>
                <th style="width: 46%; text-align: left;">{{ $labels['product'] }}</th>
                <th style="width: 7%;">{{ $labels['unit'] }}</th>
                <th style="width: 12%;">{{ $labels['quantity'] }}</th>
                <th style="width: 16%;">{{ $labels['unitPrice'] }}</th>
                <th style="width: 13%;">{{ $labels['value'] }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($items as $item)
                <tr>
                    <td class="index">{{ $item['nr'] }}</td>
                    <td class="product">{{ $item['name'] }}</td>
                    <td>{{ $item['um'] }}</td>
                    <td class="as-ordered">{{ $labels['asOrdered'] }}</td>
                    <td>{{ $item['unit_price'] }} {{ $item['currency'] }}</td>
                    <td class="as-ordered"><strong>{{ $labels['asOrdered'] }}</strong></td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="note">{{ $labels['note'] }}</div>
    @foreach ($additionalNotes as $note)
        <div class="additional-note">{{ $note }}</div>
    @endforeach

    <table class="contacts">
        <tr>
            <td>{{ $supplier['email'] ?? '-' }}</td>
            <td>{{ $supplier['phone'] ?? '-' }}</td>
            <td>{{ $supplier['website'] ? preg_replace('#^https?://#i', '', rtrim($supplier['website'], '/')) : '-' }}</td>
        </tr>
    </table>
</body>
</html>
