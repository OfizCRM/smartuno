{{--
    The offer as the customer sees it, in a browser.

    THIS PAGE IS SERVED TO SOMEBODY WITH NO ACCOUNT. Read the docblock on
    App\Modules\Offers\Http\Controllers\PublicOfferController before changing
    anything here. Three rules follow from it.

    1. IT PRINTS ONLY WHAT IT WAS GIVEN, AND IT WAS GIVEN ONLY THE DOCUMENT.
       The same fields as resources/views/pdf/offer.blade.php: the seller's
       printed identity, the buyer as addressed, the lines, the totals, the
       validity, the notes, the footer. There is no $user, no workspace, no
       conversation, no ai_json and no seller profile in scope, and none of them
       may be added. A field that is not on the PDF the customer already has is
       a field this page must not invent.

    2. NO INERTIA, NO APP LAYOUT, NO @vite. It does not @extends anything. The
       application layout would ship the shared Inertia props — including the
       whole User model, which HandleInertiaRequests still shares — into a
       document a stranger is holding.

    3. NOTHING IS FETCHED. The response carries default-src 'none' with a single
       exception for the inline <style> below. No script tag, no <img>, no web
       font, no external stylesheet will load, silently, and that is intended —
       so keep the CSS inline and keep the page self-contained.

    On the font: the stack starts with DejaVu Sans, the same face the PDF is
    rendered in, so the page and the file look like the same document to the
    person holding both. Every fallback after it is a system face that carries
    the Romanian comma-below letters (ș U+0219, ț U+021B — not the cedilla
    lookalikes), which is what keeps "Ofertă", "preț" and "Brașov" from
    rendering as boxes on a phone.

    Everything is Romanian, written out rather than run through __(). Two
    reasons, and the second is the load-bearing one. This is the seller's own
    commercial document for a Romanian buyer, exactly as pdf/offer.blade.php is;
    and there is no session and no SetLocale on this route, so __() would resolve
    against APP_LOCALE — 'en' — and hand a Romanian customer an English quote.
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
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- Belt and braces with the X-Robots-Tag header the controller sets. --}}
    <meta name="robots" content="noindex, nofollow, noarchive, nosnippet">
    <meta name="referrer" content="no-referrer">
    <title>Ofertă {{ $offer->number }}</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        html { -webkit-text-size-adjust: 100%; }
        body {
            margin: 0;
            padding: 24px 16px 56px;
            background: #f3f4f6;
            font-family: "DejaVu Sans", "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            font-size: 15px;
            line-height: 1.5;
            color: #1f2937;
        }
        .sheet {
            max-width: 760px;
            margin: 0 auto;
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            padding: 32px 32px 36px;
        }
        .head { display: flex; flex-wrap: wrap; gap: 20px; justify-content: space-between; align-items: flex-start; }
        .head > div { min-width: 220px; }
        .head .doc { text-align: right; margin-left: auto; }
        .party-name { font-size: 18px; font-weight: 700; color: #111827; }
        .muted { color: #6b7280; font-size: 14px; }
        .muted div { margin-top: 2px; }
        .doc-title { font-size: 26px; font-weight: 700; color: #047857; letter-spacing: 0.04em; }
        .doc-number { font-size: 17px; font-weight: 700; color: #111827; margin-top: 6px; }
        .rule { border-top: 2px solid #047857; margin: 22px 0 0; }
        .section { margin-top: 26px; }
        .section-title {
            font-size: 11px; font-weight: 700; text-transform: uppercase;
            letter-spacing: 0.08em; color: #6b7280; margin-bottom: 6px;
        }
        .scroll { overflow-x: auto; margin-top: 10px; }
        table.items { width: 100%; border-collapse: collapse; min-width: 520px; }
        table.items thead tr { background: #f3f4f6; }
        table.items th {
            padding: 9px 8px; text-align: left; font-size: 11px; font-weight: 700;
            text-transform: uppercase; letter-spacing: 0.05em; color: #4b5563;
            border-bottom: 1px solid #d1d5db;
        }
        table.items td { padding: 10px 8px; border-bottom: 1px solid #e5e7eb; font-size: 14px; }
        .num { text-align: right; white-space: nowrap; }
        .mid { text-align: center; }
        table.totals { border-collapse: collapse; margin-left: auto; margin-top: 16px; }
        table.totals td { padding: 5px 0; font-size: 15px; }
        table.totals td.label { text-align: right; color: #4b5563; padding-right: 18px; }
        table.totals td.value { text-align: right; white-space: nowrap; font-weight: 700; min-width: 130px; }
        table.totals tr.grand td { border-top: 2px solid #d1d5db; padding-top: 10px; font-size: 18px; color: #111827; }
        .discount { color: #b91c1c; }
        .mention { margin-top: 12px; font-size: 14px; color: #4b5563; }
        .validity {
            margin-top: 20px; padding: 10px 14px; border-radius: 8px;
            background: #fffbeb; color: #b45309; font-weight: 700; font-size: 15px;
        }
        .note { margin-top: 22px; padding: 12px 14px; background: #f9fafb; border-left: 3px solid #d1d5db; }
        .note-body { line-height: 1.65; font-size: 14px; }
        .footer { margin-top: 32px; border-top: 1px solid #e5e7eb; padding-top: 14px; color: #6b7280; font-size: 13px; }
        .empty { padding: 16px 8px; color: #6b7280; }

        @media (max-width: 640px) {
            body { padding: 12px 10px 40px; font-size: 14px; }
            .sheet { padding: 20px 16px 24px; border-radius: 8px; }
            .head .doc { text-align: left; margin-left: 0; }
            .doc-title { font-size: 22px; }
            table.totals { width: 100%; }
        }

        @media print {
            body { background: #ffffff; padding: 0; }
            .sheet { border: 0; border-radius: 0; padding: 0; max-width: none; }
        }
    </style>
</head>
<body>

<main class="sheet">

    <div class="head">
        <div>
            <div class="party-name">{{ $sellerName ?? '—' }}</div>
            <div class="muted" style="margin-top: 4px;">
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
        </div>
        <div class="doc">
            <div class="doc-title">OFERTĂ</div>
            <div class="doc-number">{{ $offer->number }}</div>
            <div class="muted" style="margin-top: 4px;">
                <div>Emisă: {{ $issuedAt }}</div>
                @if($validUntil)
                    <div>Valabilă până la: {{ $validUntil }}</div>
                @endif
            </div>
        </div>
    </div>

    <div class="rule"></div>

    <div class="section">
        <div class="section-title">Către</div>
        @if($contact)
            <div class="party-name" style="font-size: 16px;">{{ $buyerName ?? '—' }}</div>
            <div class="muted" style="margin-top: 3px;">
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

    {{-- The table keeps its columns and scrolls sideways on a narrow phone
         rather than collapsing: a price list that reflows is a price list the
         customer reads a quantity off the wrong row of. --}}
    <div class="scroll">
        <table class="items">
            <thead>
                <tr>
                    <th style="width: 30px;">#</th>
                    <th>Denumire</th>
                    <th class="mid" style="width: 60px;">U.M.</th>
                    <th class="num" style="width: 76px;">Cantitate</th>
                    <th class="num" style="width: 108px;">Preț unitar</th>
                    <th class="num" style="width: 118px;">Valoare</th>
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
                        <td colspan="6" class="empty">Oferta nu conține produse sau servicii.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <table class="totals">
        <tr>
            <td class="label">Subtotal</td>
            <td class="value">{{ $money($offer->subtotal_cents) }} {{ $currency }}</td>
        </tr>
        @if($offer->discount_cents > 0)
            <tr>
                <td class="label">{{ $offer->discount_label ?: 'Discount' }}</td>
                <td class="value discount">&minus; {{ $money($offer->discount_cents) }} {{ $currency }}</td>
            </tr>
        @endif
        {{-- Only when there is something to say — the same condition the PDF
             uses. A firm that delivers nothing does not print "Transport". --}}
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
                <td class="label">TVA{{ $rate ? " ({$rate}%)" : '' }}</td>
                <td class="value">{{ $money($offer->vat_cents) }} {{ $currency }}</td>
            </tr>
        @endif
        <tr class="grand">
            <td class="label" style="font-weight: 700; color: #111827;">Total de plată</td>
            <td class="value">{{ $money($offer->total_cents) }} {{ $currency }}</td>
        </tr>
    </table>

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
            {{-- Escaped first, then the line breaks are put back: the note is
                 free text a person typed, and it must never reach the browser as
                 markup. This is the only place on the page that emits unescaped
                 output, and what it emits is <br> and nothing else. --}}
            <div class="note-body">{!! nl2br(e($offer->notes)) !!}</div>
        </div>
    @endif

    @if(trim((string) ($settings['footer_text'] ?? '')) !== '')
        <div class="footer">{!! nl2br(e($settings['footer_text'])) !!}</div>
    @endif

</main>

</body>
</html>
