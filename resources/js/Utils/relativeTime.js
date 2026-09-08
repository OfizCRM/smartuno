/**
 * Relative timestamps ("acum 3 minute") for notification lists.
 *
 * Utils/datetime.js deliberately only formats absolute instants in a chosen
 * timezone; a notification feed wants the opposite — how long ago, in the
 * reader's own language. Intl.RelativeTimeFormat already knows Romanian's
 * rules (including the "de" that appears above 19), so there is no table of
 * strings here and nothing to translate.
 */

const MINUTE = 60;
const HOUR = 60 * MINUTE;
const DAY = 24 * HOUR;
const WEEK = 7 * DAY;

/**
 * Seconds between now and an ISO 8601 instant. Negative means the past.
 */
function secondsSince(iso, now) {
    const then = Date.parse(iso);

    return Number.isNaN(then) ? null : Math.round((then - now) / 1000);
}

/**
 * "acum 3 minute" / "acum 2 ore" / "acum 5 zile".
 *
 * Anything older than a week returns null rather than "acum 9 săptămâni",
 * which reads worse than a date — callers fall back to an absolute format.
 */
export function relativeTime(iso, locale = 'ro', now = Date.now()) {
    const delta = secondsSince(iso, now);

    if (delta === null || delta > 0) {
        // A future timestamp is a clock-skew artefact, not something to render.
        return delta === null ? null : justNow(locale);
    }

    const ago = Math.abs(delta);

    if (ago < MINUTE) {
        return justNow(locale);
    }

    const fmt = new Intl.RelativeTimeFormat(locale, { numeric: 'always' });

    if (ago < HOUR) {
        return fmt.format(-Math.floor(ago / MINUTE), 'minute');
    }

    if (ago < DAY) {
        return fmt.format(-Math.floor(ago / HOUR), 'hour');
    }

    if (ago < WEEK) {
        return fmt.format(-Math.floor(ago / DAY), 'day');
    }

    return null;
}

/**
 * Under a minute. RelativeTimeFormat would say "acum 0 secunde", which is
 * worse than the idiom every locale already has for this.
 */
function justNow(locale) {
    return new Intl.RelativeTimeFormat(locale, { numeric: 'auto' }).format(0, 'second');
}

/**
 * Which day bucket a notification belongs to, for grouped lists.
 * Returns 'today' | 'yesterday' | 'earlier'.
 */
export function dayBucket(iso, now = Date.now()) {
    const then = new Date(iso);

    if (Number.isNaN(then.getTime())) {
        return 'earlier';
    }

    const startOfToday = new Date(now);
    startOfToday.setHours(0, 0, 0, 0);

    if (then >= startOfToday) {
        return 'today';
    }

    const startOfYesterday = new Date(startOfToday);
    startOfYesterday.setDate(startOfYesterday.getDate() - 1);

    return then >= startOfYesterday ? 'yesterday' : 'earlier';
}
