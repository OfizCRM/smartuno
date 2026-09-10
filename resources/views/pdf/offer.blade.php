{{--
    The offer as the customer receives it.

    TWO THINGS HERE ARE NOT STYLISTIC.

    1. font-family stays DejaVu Sans. It is the only font dompdf ships that
       carries the Romanian comma-below letters, and it is the entire reason
       "Ofertă", "preț" and "Brașov" render as words rather than as boxes.
       Swapping it for a nicer face silently mangles every second Romanian name.

    2. dompdf runs with enable_remote = false (config/dompdf.php in the package,
       line 270; this application does not publish an override). Any <img> with
       an http URL is fetched by nobody and draws nothing at all — no error, no
       placeholder, just a gap where the logo was. A logo has to arrive as a
       base64 data: URI, which is why the seller array carries the bytes and not
       an address.

    Layout is tables, not flexbox. dompdf does not implement display:flex — the
    invoice template's .header uses it and stacks instead of sitting side by
    side, which is a bug nobody has looked at because that PDF is internal.

    Everything is Romanian, written out rather than run through __(). This file
    is the seller's own commercial document for a Romanian buyer; it is not the
    interface, and it must not change language because the person who pressed
    the button happens to be reading the app in English.
--}}
@php
    $currency = $offer->currency === 'RON' ? 'lei' : $offer->currency;
    $rate = $offer->vat_rate !== null ? rtrim(rtrim(number_format((float) $offer->vat_rate, 2, ',', ''), '0'), ',') : null;
    $sellerName = trim((string) ($seller['legal_name'] ?? '')) !== '' ? $seller['legal_name'] : null;
    $buyerName = $contact?->company ?: ($contact?->full_name ?: null);
@endphp
<!DOCTYPE html>
<html lang="ro">
<head>
    <meta charset="UTF-8">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: "DejaVu Sans", sans-serif; font-size: 11px; color: #1f2937; padding: 36px 40px; }
        .layout { width: 100%; border-collapse: collapse; }
        .layout td { vertical-align: top; }
        .party-name { font-size: 14px; font-weight: bold; color: #111827; }
        .muted { color: #6b7280; }
        .doc-title { font-size: 24px; font-weight: bold; color: #047857; text-align: right; letter-spacing: 0.04em; }
        .doc-meta { text-align: right; margin-top: 6px; }
        .doc-meta .number { font-size: 15px; font-weight: bold; color: #111827; }
        .section { margin-top: 26px; }
        .section-title { font-size: 9px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.08em; color: #6b7280; margin-bottom: 6px; }
        .rule { border-top: 2px solid #047857; margin-top: 18px; }
        table.items { width: 100%; border-collapse: collapse; margin-top: 8px; }
        table.items thead tr { background-color: #f3f4f6; }
        table.items th { padding: 7px 8px; text-align: left; font-size: 9px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.05em; color: #4b5563; border-bottom: 1px solid #d1d5db; }
        table.items td { padding: 8px; border-bottom: 1px solid #e5e7eb; }
        .num { text-align: right; white-space: nowrap; }
        .mid { text-align: center; }
        table.totals { width: 100%; border-collapse: collapse; }
        table.totals td { padding: 4px 0; }
        table.totals td.label { text-align: right; color: #4b5563; padding-right: 16px; }
        table.totals td.value { text-align: right; white-space: nowrap; width: 120px; font-weight: bold; }
        table.totals tr.grand td { border-top: 2px solid #d1d5db; padding-top: 9px; font-size: 14px; color: #111827; }
        .note { margin-top: 22px; padding: 10px 12px; background: #f9fafb; border-left: 3px solid #d1d5db; }
        .mention { margin-top: 10px; font-size: 10px; color: #4b5563; }
        .validity { margin-top: 18px; font-size: 12px; font-weight: bold; color: #b45309; }
        .footer { margin-top: 34px; border-top: 1px solid #e5e7eb; padding-top: 12px; color: #6b7280; font-size: 10px; }
    </style>
</head>
<body>

<table class="layout">
    <tr>
        <td style="width: 58%;">
            @if(! empty($seller['logo_data_uri']))
                {{-- A data: URI only. See the note at the top of this file. --}}
                <img src="{{ $seller['logo_data_uri'] }}" alt="" style="max-height: 54px; margin-bottom: 8px;">
            @endif
            <div class="party-name">{{ $sellerName ?? '—' }}</div>
            <div class="muted" style="margin-top: 4px; line-height: 1.5;">
                @if(! empty($seller['cui']))
                    <div>CUI: {{ $seller['cui'] }}</div>
                @endif
                @if(! empty($seller['trade_register_no']))
                    <div>Reg. Com.: {{ $seller['trade_register_no'] }}</div>
                @endif
                @if(! empty($seller['address']))
                    <div>{{ $seller['address'] }}</div>
                @endif
                @if(! empty($seller['iban']))
                    <div>IBAN: {{ $seller['iban'] }}@if(! empty($seller['bank_name'])) &middot; {{ $seller['bank_name'] }}@endif</div>
                @endif
            </div>
        </td>
        <td style="width: 42%;">
            <div class="doc-title">OFERTĂ</div>
            <div class="doc-meta">
                <div class="number">{{ $offer->number }}</div>
                <div class="muted" style="margin-top: 4px;">Emisă: {{ $issuedAt }}</div>
                @if($validUntil)
                    <div class="muted">Valabilă până la: {{ $validUntil }}</div>
                @endif
            </div>
        </td>
    </tr>
</table>

<div class="rule"></div>

<div class="section">
    <div class="section-title">Către</div>
    @if($contact)
        <div class="party-name" style="font-size: 13px;">{{ $buyerName ?? '—' }}</div>
        <div class="muted" style="margin-top: 3px; line-height: 1.5;">
            @if($contact->company && $contact->full_name)
                <div>{{ $contact->full_name }}</div>
            @endif
            @if($contact->tax_id)
                <div>CUI: {{ $contact->tax_id }}</div>
            @endif
            @if($contact->address || $contact->city)
                <div>{{ trim(implode(', ', array_filter([$contact->address, $contact->city]))) }}</div>
            @endif
            @if($contact->email)
                <div>{{ $contact->email }}</div>
            @endif
            @if($contact->phone_e164)
                <div>{{ $contact->phone_e164 }}</div>
            @endif
        </div>
    @else
        <div class="muted">—</div>
    @endif
</div>

<table class="items">
    <thead>
        <tr>
            <th style="width: 26px;">#</th>
            <th>Denumire</th>
            <th class="mid" style="width: 52px;">U.M.</th>
            <th class="num" style="width: 62px;">Cantitate</th>
            <th class="num" style="width: 96px;">Preț unitar</th>
            <th class="num" style="width: 104px;">Valoare</th>
        </tr>
    </thead>
    <tbody>
        @forelse($items as $index => $item)
            <tr>
                <td class="muted">{{ $index + 1 }}</td>
                <td>{{ $item->name }}</td>
                <td class="mid muted">{{ $item->unit }}</td>
                <td class="num">{{ $quantity($item->quantity) }}</td>
                <td class="num">{{ $money($item->unit_price_cents) }}</td>
                <td class="num">{{ $money($item->line_total_cents) }}</td>
            </tr>
        @empty
            <tr>
                <td colspan="6" class="muted" style="padding: 14px 8px;">Oferta nu conține produse sau servicii.</td>
            </tr>
        @endforelse
    </tbody>
</table>

<div class="section" style="margin-top: 16px;">
    <table class="totals">
        <tr>
            <td class="label">Subtotal</td>
            <td class="value">{{ $money($offer->subtotal_cents) }} {{ $currency }}</td>
        </tr>
        @if($offer->discount_cents > 0)
            <tr>
                <td class="label">{{ $offer->discount_label ?: 'Discount' }}</td>
                <td class="value" style="color: #b91c1c;">&minus; {{ $money($offer->discount_cents) }} {{ $currency }}</td>
            </tr>
        @endif
        {{-- Only when there is something to say. A dental clinic that
             delivers nothing was sending every quote with a written
             "Transport: Gratuit" the seller never saw on screen. --}}
        @if($offer->shipping_cents > 0 || ($settings['free_shipping_cents'] ?? 0) > 0)
        <tr>
            <td class="label">Transport</td>
            <td class="value">
                @if($offer->shipping_cents > 0)
                    {{ $money($offer->shipping_cents) }} {{ $currency }}
                @else
                    Gratuit
                @endif
            </td>
        </tr>
        @endif
        @if($offer->vat_cents > 0)
            <tr>
                {{-- A directive glued to a letter is not a directive: Blade leaves "TVA@if" as text
                     and compiles the @endif on its own, which is a parse error at render time. --}}
                <td class="label">TVA{{ $rate ? " ({$rate}%)" : '' }}</td>
                <td class="value">{{ $money($offer->vat_cents) }} {{ $currency }}</td>
            </tr>
        @endif
        <tr class="grand">
            <td class="label" style="font-weight: bold; color: #111827;">Total de plată</td>
            <td class="value">{{ $money($offer->total_cents) }} {{ $currency }}</td>
        </tr>
    </table>
</div>

@if($offer->vat_status === 'none')
    <div class="mention">Neplătitor de TVA.</div>
@elseif($offer->vat_status === 'on_collection')
    <div class="mention">TVA la încasare.</div>
@endif

@if($validUntil)
    <div class="validity">Ofertă valabilă până la {{ $validUntil }}.</div>
@endif

@if(trim((string) $offer->notes) !== '')
    <div class="note">
        <div class="section-title">Observații</div>
        {{-- Escaped first, then the line breaks are put back: the note is free
             text a person typed, and it must never reach the renderer as markup. --}}
        <div style="line-height: 1.6;">{!! nl2br(e($offer->notes)) !!}</div>
    </div>
@endif

@if(trim((string) ($settings['footer_text'] ?? '')) !== '')
    <div class="footer">{!! nl2br(e($settings['footer_text'])) !!}</div>
@endif

</body>
</html>
