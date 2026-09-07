<?php

namespace App\Support;

use DOMAttr;
use DOMCdataSection;
use DOMDocument;
use DOMElement;
use DOMText;

/**
 * Rewrite an uploaded SVG down to a strict allow-list of elements and attributes.
 *
 * An SVG is XML, and XML we serve from our own origin can carry <script>, event
 * handlers and external references. A tenant logo is uploaded by the tenant and
 * served back on our domain, so an unsanitised one is stored XSS.
 *
 * HONEST LIMIT: this is hand-rolled, and a hand-rolled sanitiser is a weaker
 * guarantee than a maintained library that tracks new bypasses. We are not using
 * enshrined/svg-sanitize — the standard choice — because it is GPL-2.0 and this
 * is a commercial product that may be distributed. That is the only reason.
 * Treat this class as a security control: extend the allow-lists deliberately,
 * never by pasting in what one customer's file happened to need.
 *
 * DEFENCE IN DEPTH: an SVG rendered through <img src> cannot run script in any
 * modern browser whatever it contains — the exposure is inline rendering
 * (dangerouslySetInnerHTML, a Blade echo) or a visitor navigating straight to
 * the stored file URL, where the browser parses it as a document. Every place
 * the product paints a logo today uses <img src> (ApplicationLogo, Sidebar,
 * AdminLayout, InboxLayout, AuthLayout, LandingLayout, CompanyLogoCard,
 * Admin/Clients/Show and the error page Blade layout), so this class covers the
 * direct-navigation case. Keep it that way: a logo must never be inlined.
 *
 * The design is an allow-list, never a blocklist. "Strip <script>" is bypassable
 * in a dozen documented ways; "keep these twenty-two elements and nothing else"
 * is not.
 */
final class SvgSanitizer
{
    private const SVG_NAMESPACE = 'http://www.w3.org/2000/svg';

    private const XMLNS_NAMESPACE = 'http://www.w3.org/2000/xmlns/';

    /**
     * Everything a logo can be drawn with. Case-sensitive, as SVG element names
     * are: <clipPath> is an element, <clippath> is not one and is deleted.
     *
     * Deliberately absent: <script> and <style> (see below), <foreignObject>
     * (embeds arbitrary HTML), <image> (would need a data: or remote URL),
     * <animate>/<set> (can retarget attributes after we have inspected them),
     * <filter>, <marker>, <a>, <metadata>, <switch>, <view>.
     *
     * <style> is refused rather than scanned or dropped: CSS reaches elements
     * through selectors, so a value scan cannot say what a rule will end up
     * applying to, and it brings @import and url() with it. Dropping it quietly
     * is the worse of the two failures — see sanitize(), which rejects the whole
     * file instead. Presentation attributes cover every logo we have seen, and
     * they are checked one by one below.
     *
     * @var list<string>
     */
    private const ALLOWED_ELEMENTS = [
        'svg', 'g', 'path', 'circle', 'ellipse', 'rect', 'line', 'polyline', 'polygon',
        'defs', 'use', 'symbol', 'title', 'desc',
        'linearGradient', 'radialGradient', 'stop',
        'clipPath', 'mask', 'pattern',
        'text', 'tspan',
    ];

    /**
     * Allowed attributes, as lowercase name => canonical SVG spelling.
     *
     * Matching is case-insensitive and the attribute is rewritten to the
     * canonical spelling, so "VIEWBOX" survives as "viewBox" instead of being
     * silently dropped — and, more to the point, "OnLoad" cannot slip past a
     * case-sensitive comparison.
     *
     * @var array<string, string>
     */
    private const ALLOWED_ATTRIBUTES = [
        // Structure and accessibility
        'id' => 'id',
        'class' => 'class',
        'style' => 'style',
        'transform' => 'transform',
        'version' => 'version',
        'viewbox' => 'viewBox',
        'preserveaspectratio' => 'preserveAspectRatio',
        'role' => 'role',
        'aria-label' => 'aria-label',
        'aria-labelledby' => 'aria-labelledby',
        'aria-hidden' => 'aria-hidden',
        'focusable' => 'focusable',

        // Geometry
        'width' => 'width',
        'height' => 'height',
        'x' => 'x',
        'y' => 'y',
        'dx' => 'dx',
        'dy' => 'dy',
        'd' => 'd',
        'cx' => 'cx',
        'cy' => 'cy',
        'r' => 'r',
        'rx' => 'rx',
        'ry' => 'ry',
        'x1' => 'x1',
        'y1' => 'y1',
        'x2' => 'x2',
        'y2' => 'y2',
        'points' => 'points',
        'pathlength' => 'pathLength',

        // Paint
        'fill' => 'fill',
        'fill-opacity' => 'fill-opacity',
        'fill-rule' => 'fill-rule',
        'stroke' => 'stroke',
        'stroke-width' => 'stroke-width',
        'stroke-linecap' => 'stroke-linecap',
        'stroke-linejoin' => 'stroke-linejoin',
        'stroke-miterlimit' => 'stroke-miterlimit',
        'stroke-dasharray' => 'stroke-dasharray',
        'stroke-dashoffset' => 'stroke-dashoffset',
        'stroke-opacity' => 'stroke-opacity',
        'opacity' => 'opacity',
        'color' => 'color',
        'display' => 'display',
        'visibility' => 'visibility',
        'overflow' => 'overflow',
        'clip-path' => 'clip-path',
        'clip-rule' => 'clip-rule',
        'mask' => 'mask',

        // Gradients, patterns, clips and masks
        'gradientunits' => 'gradientUnits',
        'gradienttransform' => 'gradientTransform',
        'spreadmethod' => 'spreadMethod',
        'fx' => 'fx',
        'fy' => 'fy',
        'offset' => 'offset',
        'stop-color' => 'stop-color',
        'stop-opacity' => 'stop-opacity',
        'patternunits' => 'patternUnits',
        'patterncontentunits' => 'patternContentUnits',
        'patterntransform' => 'patternTransform',
        'clippathunits' => 'clipPathUnits',
        'maskunits' => 'maskUnits',
        'maskcontentunits' => 'maskContentUnits',

        // Text
        'font-family' => 'font-family',
        'font-size' => 'font-size',
        'font-weight' => 'font-weight',
        'font-style' => 'font-style',
        'text-anchor' => 'text-anchor',
        'letter-spacing' => 'letter-spacing',
        'word-spacing' => 'word-spacing',
        'dominant-baseline' => 'dominant-baseline',
    ];

    /**
     * The attributes that name another document, allowed only as a same-document
     * fragment: <use href="#logo"> works, <use href="https://evil/x.svg#a"> does
     * not.
     *
     * @var list<string>
     */
    private const REFERENCE_ATTRIBUTES = ['href', 'xlink:href'];

    /**
     * Return the cleaned SVG, or null when the file cannot be made safe.
     *
     * The return value is always re-serialised from the cleaned DOM. The
     * original bytes are never handed back, so anything the walk below did not
     * understand is gone by construction rather than by having been matched.
     */
    public static function sanitize(string $svg): ?string
    {
        if (trim($svg) === '') {
            return null;
        }

        // Refused before the parser sees them, not after: an entity declaration
        // is billion-laughs and file:///etc/passwd both, and there is no logo
        // that needs one. The parse below is configured to refuse them too —
        // this is the cheap belt to that braces.
        if (preg_match('/<!\s*ENTITY/i', $svg) === 1) {
            return null;
        }
        if (preg_match('/<!\s*DOCTYPE[^>\[]*\[/i', $svg) === 1) {
            return null;
        }

        $document = self::parse($svg);
        if ($document === null) {
            return null;
        }

        $doctype = $document->doctype;
        if ($doctype !== null && ($doctype->internalSubset !== null || $doctype->entities->length > 0)) {
            return null;
        }

        $root = $document->documentElement;
        if (! $root instanceof DOMElement || ! self::isAllowedElement($root)) {
            return null;
        }
        if ($root->localName !== 'svg') {
            return null;
        }

        // Refused, not stripped. Illustrator's SVG dialog defaults to "Internal
        // CSS", which puts every colour in a <style> block and references it
        // from class attributes on the shapes. Deleting the block leaves a
        // well-formed file with no paint on it, so the tenant gets a success
        // toast and a solid-black logo on every screen. A rejection they can act
        // on — re-export without internal CSS — is the honest answer.
        if ($document->getElementsByTagNameNS('*', 'style')->length > 0) {
            return null;
        }

        self::cleanElement($root);

        // A fragment uploaded without a namespace declaration would serialise
        // back as XML the browser cannot identify as SVG, and render as nothing
        // when opened directly.
        if ($root->namespaceURI === null) {
            $root->setAttributeNS(self::XMLNS_NAMESPACE, 'xmlns', self::SVG_NAMESPACE);
        }

        // saveXML() on the element rather than the document: it emits the tree
        // we cleaned and nothing else, leaving any DOCTYPE or processing
        // instruction that came in behind.
        $cleaned = $document->saveXML($root);

        return is_string($cleaned) && $cleaned !== '' ? $cleaned : null;
    }

    /**
     * Parse with external entity resolution refused outright, and reject
     * anything that is not well-formed XML rather than trying to repair it —
     * a parser that guesses and a browser that guesses will not guess alike.
     */
    private static function parse(string $svg): ?DOMDocument
    {
        $document = new DOMDocument;
        $document->preserveWhiteSpace = false;
        $document->strictErrorChecking = false;

        // libxml 2.9+ refuses network and file entities by default, but a
        // default is a library setting and not a promise this class can make.
        // Restoring null afterwards puts the default loader back for the rest
        // of the request; libxml_get_external_entity_loader() only exists from
        // PHP 8.4 and composer.json allows 8.2.
        libxml_set_external_entity_loader(static fn () => null);
        $previousErrorHandling = libxml_use_internal_errors(true);

        try {
            $parsed = $document->loadXML($svg, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrorHandling);
            libxml_set_external_entity_loader(null);
        }

        return $parsed ? $document : null;
    }

    /** Recurse depth-first, deleting whole subtrees the allow-list does not name. */
    private static function cleanElement(DOMElement $element): void
    {
        // Snapshot the children: removeChild() mutates the live NodeList and
        // would make a foreach skip every second node.
        foreach (iterator_to_array($element->childNodes) as $child) {
            if ($child instanceof DOMElement) {
                if (! self::isAllowedElement($child)) {
                    $element->removeChild($child);

                    continue;
                }

                self::cleanElement($child);

                continue;
            }

            // Plain text survives — saveXML() escapes it, so it cannot become
            // markup. Everything else goes: comments and processing
            // instructions are known smuggling channels, CDATA is how markup
            // gets past a serialiser, and an entity reference should not have
            // reached here at all.
            if (! $child instanceof DOMText || $child instanceof DOMCdataSection) {
                $element->removeChild($child);
            }
        }

        self::cleanAttributes($element);
    }

    /**
     * An element is kept only if the allow-list names it AND it is in the SVG
     * namespace (or in none at all, for a fragment written without xmlns).
     * Without the namespace half, <xhtml:title> would pass as <title>.
     */
    private static function isAllowedElement(DOMElement $element): bool
    {
        if ($element->namespaceURI !== null && $element->namespaceURI !== self::SVG_NAMESPACE) {
            return false;
        }

        return in_array($element->localName, self::ALLOWED_ELEMENTS, true);
    }

    private static function cleanAttributes(DOMElement $element): void
    {
        // Namespace declarations are deliberately not filtered here, and cannot
        // be: PHP's DOM does not expose an xmlns:* declaration through
        // ->attributes at all, and removeAttributeNS() on the xmlns namespace is
        // a no-op, so a foreign declaration survives into the output verbatim.
        // It is inert on its own — an element or an attribute in that namespace
        // is dropped by isAllowedElement() and by the allow-list below, which is
        // what actually contains a foreign namespace. Rejecting the file over a
        // stray declaration would refuse the Illustrator exports that carry
        // three of them and draw nothing with any of them.
        foreach (iterator_to_array($element->attributes) as $attribute) {
            $name = self::attributeName($attribute);
            $value = (string) $attribute->nodeValue;

            // Named explicitly even though the allow-list already excludes
            // every one of them: an event handler is the single thing that must
            // never survive, and a reader should not have to diff two lists to
            // be sure it cannot.
            if (str_starts_with($name, 'on')) {
                $element->removeAttributeNode($attribute);

                continue;
            }

            if (in_array($name, self::REFERENCE_ATTRIBUTES, true)) {
                if (! str_starts_with(self::normalise($value), '#')) {
                    $element->removeAttributeNode($attribute);
                }

                continue;
            }

            $canonical = self::ALLOWED_ATTRIBUTES[$name] ?? null;
            if ($canonical === null || ! self::valueIsSafe($value)) {
                $element->removeAttributeNode($attribute);

                continue;
            }

            // Rewrite to the canonical spelling. Prefixed attributes are left
            // alone: the only one that reaches here is xlink:href, handled above.
            if ($attribute->prefix === '' && $attribute->nodeName !== $canonical) {
                $element->removeAttributeNode($attribute);
                $element->setAttribute($canonical, $value);
            }
        }
    }

    /** "xlink:href" for a prefixed attribute, "viewbox" for a plain one. */
    private static function attributeName(DOMAttr $attribute): string
    {
        $prefix = $attribute->prefix;

        return $prefix !== ''
            ? strtolower($prefix).':'.strtolower($attribute->localName)
            : strtolower($attribute->localName);
    }

    /**
     * Reject a value that names a scheme or a remote resource.
     *
     * Embedded raster is NOT allowed: no data: URI of any media type gets
     * through. <image> is not an allowed element, so nothing that survives this
     * class has a legitimate use for one, and "data:image/svg+xml" differs from
     * "data:image/png" by a substring nobody should be relying on.
     */
    private static function valueIsSafe(string $value): bool
    {
        $normalised = self::normalise($value);

        if (str_contains($normalised, 'javascript:')
            || str_contains($normalised, 'vbscript:')
            || str_contains($normalised, 'data:')) {
            return false;
        }

        // A url() that is not a same-document reference reaches another origin:
        // a tracking pixel at best, and a fetch the tenant's visitors did not
        // agree to. url(#gradient) is what a logo actually needs.
        if (preg_match('/url\((?![\'"]?#)/', $normalised) === 1) {
            return false;
        }

        return ! str_contains($normalised, '@import') && ! str_contains($normalised, 'expression(');
    }

    /**
     * Decode and flatten a value before looking at it. "java&#115;cript:" and
     * "java\nscript:" are both one browser decode away from the scheme we are
     * testing for, so the test has to run after that decode, not before it.
     */
    private static function normalise(string $value): string
    {
        $decoded = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return strtolower((string) preg_replace('/[\s\x00-\x20\x7f]+/', '', $decoded));
    }
}
