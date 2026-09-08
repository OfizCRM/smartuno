{{--
    Shared layout for the transactional emails stored in the `templates` table
    (type = 'email'). The row holds prose only — an <h1> and some <p> — so an
    admin editing it in /admin/email-system cannot break the chrome around it.

    Everything else arrives here from the row's `meta`, already placeholder-
    substituted by MailService::renderTemplate().

    Expected data:
      string      $subject       plain text, used only for the <title>
      string      $preheader     inbox preview line, plain text
      ?string     $label         uppercase mint label in the header bar
      string      $body          trusted HTML prose; values inside it are escaped
      ?string     $ctaLabel      button text; no label means no button
      ?string     $ctaUrl        button href
      bool        $fallback      render the "copy this address" block
      ?string     $note          muted closing note above the rule
      string      $appName       branding, resolved from config by the caller
      ?string     $tagline
      ?string     $supportEmail

    The chrome is written in Romanian on purpose: APP_LOCALE is 'en' on every
    install, but the template rows themselves are Romanian-only, so __() would
    put an English footer under Romanian copy.

    Email HTML, not web HTML: tables for layout, everything inline-styled, no
    flexbox/grid/custom properties/background-image/web fonts. The button is a
    one-row table carrying both its bgcolor and its padding on the <td>, and the
    gaps around it are spacer rows: Outlook renders through Word, which has no
    inline-block box model and applies neither padding on an <a> nor margin on a
    <table>.
--}}
@php
    $font = "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif";

    // A cta_url arrives as a substituted placeholder, so it is only as trustworthy
    // as the call site that filled it. Anything that is not an ordinary web link
    // loses the button rather than becoming a javascript: href.
    $ctaHref = null;
    if (! empty($ctaLabel) && ! empty($ctaUrl)) {
        $scheme = strtolower((string) parse_url(trim((string) $ctaUrl), PHP_URL_SCHEME));
        if (in_array($scheme, ['http', 'https'], true)) {
            $ctaHref = trim((string) $ctaUrl);
        }
    }

    $showFallback = $ctaHref !== null && $fallback;
@endphp
<!DOCTYPE html>
<html lang="ro">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<meta name="color-scheme" content="light">
<meta name="supported-color-schemes" content="light">
<title>{{ $subject }}</title>
<style type="text/css">
    body { margin: 0; padding: 0; width: 100% !important; background-color: #F7F6F1; -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
    table { border-collapse: collapse; mso-table-lspace: 0pt; mso-table-rspace: 0pt; }
    img { border: 0; outline: none; text-decoration: none; -ms-interpolation-mode: bicubic; }
    a { color: #047857; }

    /* The prose inside the content cell is typed by an admin, so it cannot be
       inline-styled at write time. The cell below carries the base font as well —
       Outlook honours this block unreliably. */
    .su-prose h1 { margin: 0 0 16px; font-size: 23px; line-height: 1.3; font-weight: 700; color: #16241D; }
    .su-prose h2 { margin: 26px 0 10px; font-size: 17px; line-height: 1.4; font-weight: 700; color: #16241D; }
    .su-prose p { margin: 0 0 14px; font-size: 15px; line-height: 1.65; color: #3C4B43; }
    .su-prose ul, .su-prose ol { margin: 0 0 14px; padding: 0 0 0 20px; }
    .su-prose li { margin: 0 0 6px; font-size: 15px; line-height: 1.65; color: #3C4B43; }
    .su-prose a { color: #047857; text-decoration: underline; }
    .su-prose strong, .su-prose b { color: #16241D; }
    .su-prose blockquote { margin: 0 0 14px; padding: 2px 0 2px 14px; border-left: 3px solid #E1E6E0; color: #6E7C74; font-size: 15px; line-height: 1.65; }

    /* Gmail on Android/iOS ignores the color-scheme meta tags and runs its own
       colour transform: it darkens the near-white panels but keeps the declared
       dark text, which drops the footer to about 1.2:1. The green header inverts
       safely on its own. These rules live in the head block, so a client that
       strips <style> strips the fix along with it — that is the residual risk. */
    @media (prefers-color-scheme: dark) {
        .su-ground { background-color: #101A16 !important; }
        .su-body-cell { background-color: #16221D !important; }
        .su-prose, .su-prose p, .su-prose li, .su-prose blockquote { color: #D6DFD9 !important; }
        .su-prose h1, .su-prose h2, .su-prose strong, .su-prose b { color: #FFFFFF !important; }
        .su-prose a { color: #7CE3B1 !important; }
        .su-muted { color: #A7B4AC !important; }
        .su-muted a { color: #7CE3B1 !important; }
        .su-rule { border-top-color: #2C3B33 !important; }
        .su-footer-cell { background-color: #101A16 !important; border-top-color: #2C3B33 !important; }
        .su-footer-name { color: #FFFFFF !important; }
    }

    /* Outlook.com rewrites the same colours behind these attribute hooks. */
    [data-ogsc] .su-body-cell, [data-ogsb] .su-body-cell { background-color: #16221D !important; }
    [data-ogsc] .su-prose, [data-ogsc] .su-prose p, [data-ogsc] .su-prose li { color: #D6DFD9 !important; }
    [data-ogsc] .su-prose h1, [data-ogsc] .su-prose h2, [data-ogsc] .su-prose strong { color: #FFFFFF !important; }
    [data-ogsc] .su-muted { color: #A7B4AC !important; }
    [data-ogsc] .su-footer-cell, [data-ogsb] .su-footer-cell { background-color: #101A16 !important; }
    [data-ogsc] .su-footer-name { color: #FFFFFF !important; }

    @media only screen and (max-width: 620px) {
        .su-shell { width: 100% !important; }
        .su-pad { padding-left: 20px !important; padding-right: 20px !important; }
        .su-stack { display: block !important; width: 100% !important; text-align: left !important; }
        .su-stack-gap { padding-top: 6px !important; }
    }
</style>
</head>
<body style="margin:0; padding:0; background-color:#F7F6F1;">

<div style="display:none; max-height:0; overflow:hidden; mso-hide:all; opacity:0; font-size:1px; line-height:1px; color:#F7F6F1;">{{ $preheader }}</div>
{{-- Padding characters, or the client spills the start of the body into the inbox preview. --}}
<div style="display:none; max-height:0; overflow:hidden; mso-hide:all; opacity:0; font-size:1px; line-height:1px; color:#F7F6F1;">&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;</div>

<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" class="su-ground" style="background-color:#F7F6F1;">
<tr>
<td align="center" style="padding:32px 12px;">

    <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="576" class="su-shell" style="width:576px; max-width:576px;">

        {{-- Header --}}
        <tr>
        <td bgcolor="#047857" class="su-pad" style="background-color:#047857; border-radius:6px 6px 0 0; padding:22px 32px;">
            <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%">
            <tr>
                <td class="su-stack" align="left" style="font-family:{!! $font !!}; font-size:17px; line-height:1.2; font-weight:700; color:#FFFFFF;">{{ $appName }}</td>
                @if (! empty($label))
                    <td class="su-stack su-stack-gap" align="right" style="font-family:{!! $font !!}; font-size:11px; line-height:1.2; font-weight:600; letter-spacing:1.1px; text-transform:uppercase; color:#7CE3B1;">{{ $label }}</td>
                @endif
            </tr>
            </table>
        </td>
        </tr>

        {{-- Body --}}
        <tr>
        <td bgcolor="#FFFFFF" class="su-pad su-body-cell" style="background-color:#FFFFFF; padding:38px 32px 32px;">

            <div class="su-prose" style="font-family:{!! $font !!}; font-size:15px; line-height:1.65; color:#3C4B43;">{!! $body !!}</div>

            @if ($ctaHref !== null)
                {{-- Word applies neither margin on a <table> nor padding on an inline
                     <a>, so the gap is a spacer row and the button's padding is on
                     the <td>. mso-padding-alt keeps the whole cell clickable there. --}}
                <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%">
                <tr><td height="26" style="height:26px; line-height:26px; font-size:0;">&nbsp;</td></tr>
                </table>
                <table role="presentation" border="0" cellpadding="0" cellspacing="0">
                <tr>
                <td align="center" bgcolor="#047857" style="background-color:#047857; border-radius:6px; padding:13px 26px;">
                    <a href="{{ $ctaHref }}" target="_blank" rel="noopener" style="display:inline-block; mso-padding-alt:13px 26px; font-family:{!! $font !!}; font-size:15px; line-height:1.2; font-weight:600; color:#FFFFFF; text-decoration:none;">{{ $ctaLabel }}</a>
                </td>
                </tr>
                </table>
            @endif

            @if ($showFallback)
                <p class="su-muted" style="margin:22px 0 0; font-family:{!! $font !!}; font-size:13px; line-height:1.6; color:#6E7C74;">Dacă butonul nu funcționează, copiază adresa de mai jos în browser:</p>
                <p class="su-muted" style="margin:6px 0 0; font-family:{!! $font !!}; font-size:13px; line-height:1.6; word-break:break-all;"><a href="{{ $ctaHref }}" target="_blank" rel="noopener" style="color:#047857; text-decoration:underline; word-break:break-all;">{{ $ctaHref }}</a></p>
            @endif

            @if (! empty($note))
                <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%">
                <tr><td height="28" style="height:28px; line-height:28px; font-size:0;">&nbsp;</td></tr>
                <tr>
                <td class="su-muted su-rule" style="border-top:1px solid #E1E6E0; padding:16px 0 0; font-family:{!! $font !!}; font-size:13px; line-height:1.65; color:#6E7C74;">{!! $note !!}</td>
                </tr>
                </table>
            @endif

        </td>
        </tr>

        {{-- Footer --}}
        <tr>
        <td bgcolor="#EBF3EF" class="su-pad su-footer-cell" style="background-color:#EBF3EF; border-top:1px solid #CFDAD3; border-radius:0 0 6px 6px; padding:20px 32px;">
            <p class="su-footer-name" style="margin:0; font-family:{!! $font !!}; font-size:13px; line-height:1.5; color:#16241D;"><strong style="font-weight:700;">{{ $appName }}</strong>@if (! empty($tagline))<span style="color:#6E7C74;"> — {{ $tagline }}</span>@endif</p>
            <p class="su-muted" style="margin:8px 0 0; font-family:{!! $font !!}; font-size:12px; line-height:1.6; color:#6E7C74;">Acesta este un mesaj automat trimis de {{ $appName }}.@if (! empty($supportEmail)) Dacă ai nevoie de ajutor, scrie-ne la <a href="mailto:{{ $supportEmail }}" style="color:#047857; text-decoration:underline;">{{ $supportEmail }}</a>.@endif</p>
        </td>
        </tr>

    </table>

</td>
</tr>
</table>

</body>
</html>
