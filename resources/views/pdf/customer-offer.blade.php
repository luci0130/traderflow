@php
    /**
     * A pre-rendered PNG icon (raster; mpdf's SVG renderer corrupts following
     * colour state). Passing a white colour selects the white variant.
     */
    $icon = function (string $name, string $color = '#C32026', int $size = 16): string {
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
    body { margin: 0; font-family: inter, sans-serif; color: #333333; font-size: 13px; line-height: 1.35; background: #FAFAFA; }
    .page { padding: 22px 24px 6px 24px; }

    .w5 { font-family: intermedium, inter, sans-serif; }
    .w6 { font-family: intersemibold, inter, sans-serif; }
    .w7 { font-weight: bold; }

    .primary { color: #C32026; }
    .dark { color: #222222; }
    .muted { color: #777777; }

    .label { font-family: intersemibold, inter, sans-serif; font-size: 11px; letter-spacing: 0.08em; color: #777777; }

    table { border-collapse: collapse; width: 100%; }
    td { vertical-align: top; }

    .hero { background: url('{{ $banner }}') no-repeat right top; background-size: contain; min-height: 118px; }

    .cards { border-collapse: separate; border-spacing: 12px 0; }
    .card { background: #ffffff; border: 1px solid #E4E4E4; padding: 16px 18px; }
    .card-red { background: #C32026; padding: 16px 18px; }

    .items { background: #ffffff; border: 1px solid #E4E4E4; }
    .items thead td { background: #C32026; color: #ffffff; font-family: intersemibold, inter, sans-serif; font-size: 13px; padding: 13px 8px; }
    .items tbody td { border-bottom: 1px solid #EEEEEE; padding: 13px 8px; font-family: intermedium, inter, sans-serif; }
    .items tbody tr.alt td { background: #FCFCFC; }
    .thumb { width: 46px; height: 46px; }

    .footer-bar { background: #C32026; color: #ffffff; padding: 14px 24px; font-size: 12px; width: 100%; }
</style>
</head>
<body>

{{-- Red footer bar pinned to the bottom of every page. --}}
<htmlpagefooter name="pdffooter">
    <div class="footer-bar">
        <table style="color: #ffffff;">
            <tr>
                <td style="width: 34%;">@if ($supplier['address'] !== '-'){!! $icon('pin', '#ffffff', 14) !!} <span class="w5" style="vertical-align: 3px;">{{ $supplier['address'] }}</span>@endif</td>
                <td style="width: 30%; text-align: center;">@if ($supplier['email']){!! $icon('envelope', '#ffffff', 14) !!} <span class="w5" style="vertical-align: 3px;">{{ $supplier['email'] }}</span>@endif</td>
                <td style="width: 18%; text-align: center;">@if ($supplier['phone']){!! $icon('phone', '#ffffff', 14) !!} <span class="w5" style="vertical-align: 3px;">{{ $supplier['phone'] }}</span>@endif</td>
                <td style="width: 18%; text-align: right;">@if ($supplier['website']){!! $icon('globe', '#ffffff', 14) !!} <span class="w5" style="vertical-align: 3px;">{{ preg_replace('#^https?://#', '', $supplier['website']) }}</span>@endif</td>
            </tr>
        </table>
    </div>
</htmlpagefooter>
<sethtmlpagefooter name="pdffooter" value="on" />

<div class="page">

    {{-- ============================ HEADER (banner as section background) ============================ --}}
    <div class="hero">
        <table>
            <tr>
                <td style="width: 26%; padding-right: 16px;">
                    @if ($supplier['logo'])
                        <img src="{{ $supplier['logo'] }}" height="42" />
                        <div class="label" style="margin-top: 8px;">{{ mb_strtoupper($supplier['name']) }}</div>
                    @else
                        <div style="width: 46px; height: 46px; background: #C32026; border-radius: 12px; color: #fff; font-size: 24px; font-weight: bold; text-align: center; line-height: 46px;">{{ mb_substr($supplier['brand'], 0, 1) }}</div>
                        <div class="w7 primary" style="font-size: 18px; letter-spacing: 0.02em; margin-top: 8px;">{{ mb_strtoupper($supplier['brand']) }}</div>
                        <div class="label" style="margin-top: 3px;">{{ mb_strtoupper($supplier['name']) }}</div>
                    @endif
                </td>
                <td style="width: 74%; border-left: 1px solid #E4E4E4; padding-left: 18px;">
                    <div class="w7 dark" style="font-size: 16px;">Fresh Fruits &amp; Vegetables</div>
                    <div class="w6 primary" style="font-size: 12px; margin: 3px 0 12px 0;">Quality. Freshness. Reliability.</div>
                    @if ($supplier['email'])
                        <div style="margin-bottom: 6px;">{!! $icon('envelope', '#C32026', 15) !!} <span class="w5" style="vertical-align: 3px; color: #444;">{{ $supplier['email'] }}</span></div>
                    @endif
                    @if ($supplier['phone'])
                        <div style="margin-bottom: 6px;">{!! $icon('phone', '#C32026', 15) !!} <span class="w5" style="vertical-align: 3px; color: #444;">{{ $supplier['phone'] }}</span></div>
                    @endif
                    @if ($supplier['website'])
                        <div>{!! $icon('globe', '#C32026', 15) !!} <span class="w5" style="vertical-align: 3px; color: #444;">{{ $supplier['website'] }}</span></div>
                    @endif
                </td>
            </tr>
        </table>
    </div>

    {{-- ============================ SUPPLIER / BUYER / OFFER ============================ --}}
    <table class="cards" style="margin-top: 18px;">
        <tr>
            {{-- SUPPLIER --}}
            <td class="card" style="width: 43%;">
                <div style="margin-bottom: 10px;">{!! $icon('user', '#C32026', 16) !!} <span class="label" style="vertical-align: 3px;">SUPPLIER</span></div>
                <table>
                    <tr>
                        <td style="width: 52%; padding-right: 8px;">
                            <div class="w7 dark" style="font-size: 14px; margin-bottom: 8px;">{{ mb_strtoupper($supplier['name']) }}</div>
                            <div style="margin-bottom: 3px;"><span class="muted">CIF:</span> <span class="w6">{{ $supplier['cif'] }}</span></div>
                            <div style="margin-bottom: 10px;"><span class="muted">Nr. Reg.:</span> <span class="w6">{{ $supplier['reg'] }}</span></div>
                            <div style="margin-bottom: 2px;">{!! $icon('pin', '#C32026', 13) !!} <span class="w6" style="vertical-align: 2px;">Address</span></div>
                            <div class="muted" style="font-size: 12px;">{{ $supplier['address'] }}</div>
                        </td>
                        <td style="width: 48%; padding-left: 8px;">
                            <div style="margin-bottom: 8px;">{!! $icon('bank', '#C32026', 14) !!} <span class="label" style="vertical-align: 2px;">BANK ACCOUNTS</span></div>
                            @forelse ($supplier['banks'] as $bank)
                                <div class="w6" style="font-size: 11px;">{{ $bank['bank'] }}@if ($bank['currency']) <span class="muted">· {{ $bank['currency'] }}</span>@endif</div>
                                <div class="muted" style="font-size: 10px; margin-bottom: 7px;">{{ $bank['iban'] }}</div>
                            @empty
                                <div class="muted">-</div>
                            @endforelse
                        </td>
                    </tr>
                </table>
            </td>
            {{-- BUYER --}}
            <td class="card" style="width: 35%;">
                <div style="margin-bottom: 10px;">{!! $icon('user', '#C32026', 16) !!} <span class="label" style="vertical-align: 3px;">BUYER</span></div>
                <div class="w7 dark" style="font-size: 14px; margin-bottom: 8px;">{{ mb_strtoupper($buyer['name']) }}</div>
                <div style="margin-bottom: 3px;"><span class="muted">CIF:</span> <span class="w6">{{ $buyer['cif'] }}</span></div>
                <div style="margin-bottom: 7px;"><span class="muted">Nr. Reg.:</span> <span class="w6">{{ $buyer['reg'] }}</span></div>
                <div style="margin-bottom: 2px;">{!! $icon('pin', '#C32026', 13) !!} <span class="muted" style="vertical-align: 2px;">{{ $buyer['address'] }}</span></div>
                <div style="margin-bottom: 6px;"><span class="muted">Locality:</span> {{ $buyer['locality'] }}</div>
                <div style="margin-bottom: 3px;"><span class="muted">Phone:</span> {{ $buyer['phone'] }}</div>
                <div style="margin-bottom: 6px;"><span class="muted">E-mail:</span> {{ $buyer['email'] }}</div>
                <div><span class="w6 primary">REF:</span> <span class="w6">{{ $buyer['ref'] }}</span></div>
            </td>
            {{-- OFFER --}}
            <td class="card-red" style="width: 22%; color: #ffffff;">
                <div style="text-align: right;">{!! $icon('doc', '#ffffff', 22) !!}</div>
                <div class="w7" style="color: #fff; font-size: 20px; letter-spacing: 0.18em; margin: 4px 0 14px 0;">OFERTA</div>
                <div style="color: #fff; font-size: 11px; opacity: 0.9;">OFERTA NR.</div>
                <div class="w7" style="color: #fff; font-size: 26px; margin-bottom: 12px;">{{ $offerNumber }}</div>
                <div style="color: #fff; font-size: 11px; opacity: 0.9;">DATA</div>
                <div class="w7" style="color: #fff; font-size: 15px;">{{ $offerDate }}</div>
            </td>
        </tr>
    </table>

    {{-- ============================ PRODUCTS ============================ --}}
    <table class="items" style="margin-top: 18px;">
        <thead>
            <tr>
                <td style="width: 6%; text-align: center;">#</td>
                @if ($hasProductImages)<td style="width: 8%;"></td>@endif
                <td style="width: {{ $hasProductImages ? 34 : 42 }}%;">Denumire produs</td>
                <td style="width: 8%; text-align: center;">UM</td>
                <td style="width: 10%; text-align: center;">Cantitate</td>
                <td style="width: 10%; text-align: center;">PU vânzare</td>
                <td style="width: 8%; text-align: center;">Deviz</td>
                <td style="width: 8%; text-align: center;">Discount</td>
                <td style="width: 12%; text-align: right;">Valoare</td>
            </tr>
        </thead>
        <tbody>
            @foreach ($items as $item)
                <tr @class(['alt' => $loop->odd])>
                    <td style="text-align: center;"><span class="w7 primary" style="font-size: 15px;">{{ $item['nr'] }}</span></td>
                    @if ($hasProductImages)
                        <td style="text-align: center;">
                            @if ($item['image'])
                                <img src="{{ $item['image'] }}" class="thumb" />
                            @else
                                <div class="thumb" style="background: #F3F3F3; border-radius: 10px;"></div>
                            @endif
                        </td>
                    @endif
                    <td>
                        <div class="w7 dark" style="font-size: 13px;">{{ mb_strtoupper($item['name']) }}</div>
                        @if ($item['description'] !== '')
                            <div class="muted" style="font-size: 11px; margin-top: 2px;">{{ $item['description'] }}</div>
                        @endif
                    </td>
                    <td style="text-align: center;">{{ $item['um'] }}</td>
                    <td style="text-align: center;">{{ $item['quantity'] }}</td>
                    <td style="text-align: center;">{{ $item['unit_price'] }}</td>
                    <td style="text-align: center;">{{ $item['currency'] }}</td>
                    <td style="text-align: center;">{{ $item['discount'] }}</td>
                    <td style="text-align: right;"><span class="w7 primary">{{ $item['value'] }}</span></td>
                </tr>
            @endforeach
        </tbody>
    </table>

    {{-- ============================ TOTAL + BENEFITS ============================ --}}
    <table style="margin-top: 20px;">
        <tr>
            <td style="width: 30%; padding-right: 20px;">
                <table><tr>
                    <td class="card-red" style="color: #ffffff;">
                        <table><tr>
                            <td style="width: 40px;">{!! $icon('wallet', '#ffffff', 32) !!}</td>
                            <td style="vertical-align: middle;"><span style="color: #fff; font-family: intersemibold, inter; font-size: 12px; letter-spacing: 0.08em;">TOTAL (fără TVA)</span></td>
                        </tr></table>
                        <div class="w7" style="color: #fff; font-size: 30px; margin-top: 6px;">{{ $total }}</div>
                        <div style="color: #fff; font-size: 12px; opacity: 0.9;">{{ $currency }}</div>
                    </td>
                </tr></table>
            </td>
            <td style="width: 70%; vertical-align: middle;">
                <table>
                    <tr>
                        @foreach ([['leaf', 'Produse<br>Proaspete'], ['shield', 'Calitate<br>Garantată'], ['truck', 'Livrare<br>Promptă'], ['users', 'Parteneriat<br>de Încredere']] as $i => $benefit)
                            <td style="width: 25%; padding: 0 6px; {{ $i > 0 ? 'border-left: 1px solid #ECECEC;' : '' }}">
                                <table>
                                    <tr><td style="text-align: center;">{!! $icon($benefit[0], '#C32026', 30) !!}</td></tr>
                                    <tr><td class="w6 dark" style="text-align: center; font-size: 12px; padding-top: 6px;">{!! $benefit[1] !!}</td></tr>
                                </table>
                            </td>
                        @endforeach
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    {{-- ============================ NOTES / SIGNATURE / QR ============================ --}}
    <table style="margin-top: 24px;">
        <tr>
            <td style="width: 42%;">
                <div class="w7 primary" style="font-size: 13px; letter-spacing: 0.06em;">NOTES</div>
                <div style="border-bottom: 2px solid #C32026; width: 28px; margin: 5px 0 10px 0;"></div>
                @foreach ($notes as $line)
                    <div class="muted" style="font-size: 12px; margin-bottom: 4px;">{{ $line }}</div>
                @endforeach
            </td>
            <td style="width: 32%; text-align: center; padding-top: 6px;">
                <div class="muted" style="font-size: 12px; margin-bottom: 4px;">Întocmit de,</div>
                <div class="w7 primary" style="font-size: 13px;">{{ mb_strtoupper($supplier['name']) }}</div>
                @if ($signature)
                    <img src="{{ $signature }}" style="height: 46px; margin-top: 6px;" />
                @else
                    <div style="border-bottom: 1px solid #DDDDDD; width: 130px; margin: 28px auto 0 auto;"></div>
                @endif
            </td>
            <td style="width: 26%; text-align: right;">
                <table><tr>
                    <td style="width: 76px;"><barcode code="{{ $qr }}" type="QR" error="M" size="0.85" disableborder="1" /></td>
                    <td style="text-align: left; padding-left: 8px; vertical-align: middle;">
                        <div class="muted" style="font-size: 11px;">Scanează<br>pentru a vizita</div>
                        @if ($supplier['website'])
                            <div class="w7 primary" style="font-size: 11px; margin-top: 3px;">{{ preg_replace('#^https?://#', '', $supplier['website']) }}</div>
                        @endif
                    </td>
                </tr></table>
            </td>
        </tr>
    </table>
</div>

</body>
</html>
