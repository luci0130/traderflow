@php
    /**
     * A pre-rendered PNG icon. Icons are shipped as raster assets rather than
     * inline SVG because mpdf's SVG renderer corrupts the following text/colour
     * state. Passing a white colour selects the white variant (for use on the
     * red panels), anything else the red variant.
     */
    $icon = function (string $name, string $color = '#C1272D', int $size = 16): string {
        $variant = in_array(strtolower($color), ['#fff', '#ffffff', 'white'], true) ? 'white' : 'red';
        $file = public_path('images/offer-pdf/icons/'.$name.'-'.$variant.'.png');

        return is_file($file) ? '<img src="'.$file.'" width="'.$size.'" height="'.$size.'" />' : '';
    };
@endphp
<!DOCTYPE html>
<html lang="ro">
<head>
<meta charset="utf-8">
<style>
    body { margin: 0; color: #3a3a3a; font-size: 8.2pt; font-family: dejavusans, sans-serif; }
    .page { padding: 26px 30px 0 30px; }
    .muted { color: #8a8a8a; }
    .red { color: {{ $red }}; }
    .b { font-weight: bold; }
    table { border-collapse: collapse; width: 100%; }
    td { vertical-align: top; }
    .eyebrow { color: #9a9a9a; font-size: 7.5pt; letter-spacing: 1.5px; font-weight: bold; }

    .card { border: 1px solid #ececec; border-radius: 10px; padding: 12px 14px; }

    .items { margin-top: 14px; }
    .items thead td { background: {{ $red }}; color: #fff; font-weight: bold; font-size: 7.6pt; padding: 9px 6px; }
    .items tbody td { border-bottom: 1px solid #eee; padding: 10px 6px; }
    .thumb { width: 40px; height: 40px; border-radius: 8px; background: #f4f4f4; }

    .footer-bar { background: {{ $red }}; color: #fff; padding: 12px 30px; margin-top: 20px; font-size: 8pt; }
</style>
</head>
<body>
<div class="page">

    {{-- ============================ MASTHEAD ============================ --}}
    <table>
        <tr>
            <td style="width: 27%;">
                <table>
                    <tr>
                        <td style="width: 52px;">
                            @if ($supplier['logo'])
                                <img src="{{ $supplier['logo'] }}" style="width: 46px; height: 46px;" />
                            @else
                                <div style="width: 44px; height: 44px; border: 2px solid {{ $red }}; border-radius: 50%; color: {{ $red }}; font-size: 20pt; font-weight: bold; text-align: center; line-height: 44px;">{{ mb_substr($supplier['brand'], 0, 1) }}</div>
                            @endif
                        </td>
                    </tr>
                </table>
                <div class="b red" style="font-size: 15pt; margin-top: 8px;">{{ mb_strtoupper($supplier['brand']) }}</div>
                <div class="muted" style="font-size: 6.8pt; letter-spacing: 1px;">{{ mb_strtoupper($supplier['name']) }}</div>
            </td>
            <td style="width: 40%; padding-left: 6px;">
                <div class="b" style="font-size: 13pt; color: #2f2f2f;">FRESH FRUITS &amp; VEGETABLES</div>
                <div class="b red" style="font-size: 9pt; margin: 3px 0 10px 0;">Quality. Freshness. Reliability.</div>
                @if ($supplier['email'])
                    <div style="margin-bottom: 5px;">{!! $icon('envelope', $red, 15) !!} <span style="vertical-align: 3px; color:#4a4a4a;">{{ $supplier['email'] }}</span></div>
                @endif
                @if ($supplier['phone'])
                    <div style="margin-bottom: 5px;">{!! $icon('phone', $red, 15) !!} <span style="vertical-align: 3px; color:#4a4a4a;">{{ $supplier['phone'] }}</span></div>
                @endif
                @if ($supplier['website'])
                    <div style="margin-bottom: 5px;">{!! $icon('globe', $red, 15) !!} <span style="vertical-align: 3px; color:#4a4a4a;">{{ $supplier['website'] }}</span></div>
                @endif
            </td>
            <td style="width: 33%;"><img src="{{ $banner }}" style="width: 100%;" /></td>
        </tr>
    </table>

    {{-- ============================ PARTY CARDS ============================ --}}
    <table style="margin-top: 18px;">
        <tr>
            {{-- SUPPLIER --}}
            <td style="width: 39%; padding-right: 10px;">
                <div class="card">
                    <div style="margin-bottom: 8px;">{!! $icon('user', $red, 16) !!} <span class="eyebrow" style="vertical-align: 3px;">SUPPLIER</span></div>
                    <table>
                        <tr>
                            <td style="width: 52%;">
                                <div class="b" style="font-size: 11pt; color:#222; margin-bottom: 6px;">{{ mb_strtoupper($supplier['name']) }}</div>
                                <div style="margin-bottom: 3px;"><span class="muted">CIF:</span> <span class="b">{{ $supplier['cif'] }}</span></div>
                                <div style="margin-bottom: 8px;"><span class="muted">Nr. Reg.:</span> <span class="b">{{ $supplier['reg'] }}</span></div>
                                <div style="margin-bottom: 2px;">{!! $icon('pin', $red, 13) !!} <span class="b" style="vertical-align: 2px;">Address:</span></div>
                                <div class="muted">{{ $supplier['address'] }}</div>
                                @if ($supplier['phone'])
                                    <div class="muted" style="margin-top: 4px;">{{ $supplier['phone'] }}</div>
                                @endif
                            </td>
                            <td style="width: 48%; padding-left: 8px;">
                                <div style="margin-bottom: 6px;">{!! $icon('bank', $red, 13) !!} <span class="eyebrow" style="vertical-align: 2px;">BANK ACCOUNTS</span></div>
                                @forelse ($supplier['banks'] as $bank)
                                    <div class="b" style="font-size: 7.4pt;">{{ $bank['bank'] }}</div>
                                    <div class="muted" style="font-size: 6.8pt; margin-bottom: 6px;">{{ $bank['iban'] }}</div>
                                @empty
                                    <div class="muted">-</div>
                                @endforelse
                            </td>
                        </tr>
                    </table>
                </div>
            </td>
            {{-- BUYER --}}
            <td style="width: 35%; padding-right: 10px;">
                <div class="card">
                    <div style="margin-bottom: 8px;">{!! $icon('user', $red, 16) !!} <span class="eyebrow" style="vertical-align: 3px;">BUYER</span></div>
                    <div class="b" style="font-size: 11pt; color:#222; margin-bottom: 6px;">{{ mb_strtoupper($buyer['name']) }}</div>
                    <div style="margin-bottom: 3px;"><span class="muted">CIF:</span> <span class="b">{{ $buyer['cif'] }}</span></div>
                    <div style="margin-bottom: 6px;"><span class="muted">Nr. Reg.:</span> <span class="b">{{ $buyer['reg'] }}</span></div>
                    <div style="margin-bottom: 2px;">{!! $icon('pin', $red, 13) !!} <span style="vertical-align: 2px;"><span class="muted">Address:</span> {{ $buyer['address'] }}</span></div>
                    <div style="margin-bottom: 6px;"><span class="muted">Locality:</span> {{ $buyer['locality'] }}</div>
                    <div style="margin-bottom: 3px;"><span class="muted">Phone:</span> {{ $buyer['phone'] }}</div>
                    <div style="margin-bottom: 3px;"><span class="muted">Bank:</span> {{ $buyer['bank'] }}</div>
                    <div style="margin-bottom: 6px;"><span class="muted">E-mail:</span> {{ $buyer['email'] }}</div>
                    <div><span class="b red">REF:</span> <span class="b">{{ $buyer['ref'] }}</span></div>
                </div>
            </td>
            {{-- OFERTA (red panel via td background — mpdf paints cell backgrounds
                 behind text reliably, unlike a background on a block div) --}}
            <td style="width: 26%; background-color: {{ $red }}; padding: 14px 16px; color: #ffffff;">
                <div style="text-align: right;">{!! $icon('doc', '#ffffff', 24) !!}</div>
                <div style="color:#fff; font-weight: bold; font-size: 17pt; letter-spacing: 4px; margin: 4px 0 14px 0;">OFERTA</div>
                <div style="color:#fff; font-size: 7.5pt;">OFERTA NR.</div>
                <div style="color:#fff; font-weight: bold; font-size: 20pt; margin-bottom: 12px;">{{ $offerNumber }}</div>
                <div style="color:#fff; font-size: 7.5pt;">DATA</div>
                <div style="color:#fff; font-weight: bold; font-size: 13pt;">{{ $offerDate }}</div>
            </td>
        </tr>
    </table>

    {{-- ============================ ITEMS ============================ --}}
    <table class="items">
        <thead>
            <tr>
                <td style="width: 6%; text-align: center;">Nr. crt.</td>
                <td style="width: 11%;"></td>
                <td style="width: 27%;">Denumire produs</td>
                <td style="width: 7%; text-align: center;">UM</td>
                <td style="width: 11%; text-align: center;">Cantitate</td>
                <td style="width: 12%; text-align: center;">PU vanzare<br><span style="font-size:6.8pt; font-weight:normal;">({{ $currency }})</span></td>
                <td style="width: 7%; text-align: center;">Deviz</td>
                <td style="width: 9%; text-align: center;">Discount<br><span style="font-size:6.8pt; font-weight:normal;">procentual</span></td>
                <td style="width: 10%; text-align: right;">Valoare<br><span style="font-size:6.8pt; font-weight:normal;">(fara TVA)</span></td>
            </tr>
        </thead>
        <tbody>
            @foreach ($items as $item)
                <tr>
                    <td style="text-align: center;"><span class="b red" style="font-size: 12pt;">{{ $item['nr'] }}</span></td>
                    <td>
                        @if ($item['image'])
                            <img src="{{ $item['image'] }}" class="thumb" />
                        @else
                            <div class="thumb"></div>
                        @endif
                    </td>
                    <td>
                        <div class="b" style="font-size: 8.6pt; color:#222;">{{ mb_strtoupper($item['name']) }}</div>
                        @if ($item['description'] !== '')
                            <div class="muted" style="font-size: 7.4pt; margin-top: 2px;">{{ $item['description'] }}</div>
                        @endif
                    </td>
                    <td style="text-align: center;">{{ $item['um'] }}</td>
                    <td style="text-align: center;">{{ $item['quantity'] }}</td>
                    <td style="text-align: center;">{{ $item['unit_price'] }}</td>
                    <td style="text-align: center;">{{ $item['currency'] }}</td>
                    <td style="text-align: center;">{{ $item['discount'] }}</td>
                    <td style="text-align: right;"><span class="b red">{{ $item['value'] }}</span></td>
                </tr>
            @endforeach
        </tbody>
    </table>

    {{-- ============================ TOTAL + FEATURES + THANKS ============================ --}}
    <table style="margin-top: 20px;">
        <tr>
            <td style="width: 25%; background-color: {{ $red }}; padding: 14px 16px; color: #ffffff; vertical-align: middle;">
                <span>{!! $icon('basket', '#ffffff', 24) !!}</span>
                <span style="color:#fff; font-size: 7.6pt; vertical-align: 7px; padding-left: 4px;">TOTAL (fara TVA)</span>
                <div style="color:#fff; font-weight: bold; font-size: 18pt; margin-top: 8px;">{{ $total }}</div>
                <div style="color:#fff; font-size: 9pt;">{{ $currency }}</div>
            </td>
            <td style="width: 2%;"></td>
            <td style="width: 46%;">
                <table style="text-align: center;">
                    <tr>
                        <td style="width: 25%;">{!! $icon('leaf', $red, 26) !!}<div class="b" style="font-size: 7.2pt; margin-top: 4px;">Produse<br>Proaspete</div></td>
                        <td style="width: 25%;">{!! $icon('shield', $red, 26) !!}<div class="b" style="font-size: 7.2pt; margin-top: 4px;">Calitate<br>Garantată</div></td>
                        <td style="width: 25%;">{!! $icon('truck', $red, 26) !!}<div class="b" style="font-size: 7.2pt; margin-top: 4px;">Livrare<br>Promptă</div></td>
                        <td style="width: 25%;">{!! $icon('users', $red, 26) !!}<div class="b" style="font-size: 7.2pt; margin-top: 4px;">Parteneriat<br>de Încredere</div></td>
                    </tr>
                </table>
            </td>
            <td style="width: 26%; text-align: center;">
                <div class="red" style="font-size: 17pt; font-style: italic;">Thank you!</div>
                <div class="muted" style="font-size: 7.4pt; margin-top: 4px;">Vă mulțumim pentru<br>încredere și colaborare!</div>
            </td>
        </tr>
    </table>

    {{-- ============================ NOTES + SIGNATURE + QR ============================ --}}
    <table style="margin-top: 22px;">
        <tr>
            <td style="width: 40%;">
                <div class="b red" style="letter-spacing: 1px; margin-bottom: 6px;">NOTES</div>
                @foreach ($notes as $line)
                    <div class="muted" style="margin-bottom: 3px;">{{ $line }}</div>
                @endforeach
            </td>
            <td style="width: 34%; text-align: center;">
                <div class="muted" style="margin-bottom: 4px;">Întocmit de,</div>
                <div class="b red" style="font-size: 10pt;">{{ mb_strtoupper($supplier['name']) }}</div>
                @if ($signature)
                    <img src="{{ $signature }}" style="height: 44px; margin-top: 6px;" />
                @else
                    <div style="border-bottom: 1px solid #ddd; width: 120px; margin: 26px auto 0 auto;"></div>
                @endif
            </td>
            <td style="width: 26%; text-align: right;">
                <table>
                    <tr>
                        <td style="width: 74px;">
                            <barcode code="{{ $qr }}" type="QR" error="M" size="0.82" disableborder="1" />
                        </td>
                        <td style="text-align: left; padding-left: 6px;">
                            <div class="muted" style="font-size: 7.2pt;">Scanează pentru<br>a vizita</div>
                            @if ($supplier['website'])
                                <div class="b red" style="font-size: 7.4pt;">{{ preg_replace('#^https?://#', '', $supplier['website']) }}</div>
                            @endif
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</div>

{{-- ============================ FOOTER BAR ============================ --}}
<div class="footer-bar">
    <table style="color:#fff;">
        <tr>
            <td style="width: 34%;">@if ($supplier['address'] !== '-'){!! $icon('pin', '#ffffff', 13) !!} <span style="vertical-align: 2px;">{{ $supplier['address'] }}</span>@endif</td>
            <td style="width: 30%; text-align: center;">@if ($supplier['email']){!! $icon('envelope', '#ffffff', 13) !!} <span style="vertical-align: 2px;">{{ $supplier['email'] }}</span>@endif</td>
            <td style="width: 18%; text-align: center;">@if ($supplier['phone']){!! $icon('phone', '#ffffff', 13) !!} <span style="vertical-align: 2px;">{{ $supplier['phone'] }}</span>@endif</td>
            <td style="width: 18%; text-align: right;">@if ($supplier['website']){!! $icon('globe', '#ffffff', 13) !!} <span style="vertical-align: 2px;">{{ preg_replace('#^https?://#', '', $supplier['website']) }}</span>@endif</td>
        </tr>
    </table>
</div>

</body>
</html>
