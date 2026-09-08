<?php

namespace App\Support;

/**
 * Romanian display text for the two support-ticket enums.
 *
 * `support_tickets.status` and `.priority` are stored as bare English words and are
 * matched verbatim by the admin filters, by SupportTicket::isOpen() and by the
 * Rule::in on both controllers — so nothing here touches the stored value. This
 * translates for display only, at the point the value is handed to an email
 * template, and every caller goes through it so the same enum cannot read
 * "In Progress" in one email and "În lucru" in the next.
 *
 * The locale is pinned to 'ro' for the same reason Romania::longDate() pins it: the
 * transactional templates are Romanian whatever the app locale happens to be, and
 * app()->getLocale() is 'en' unless SetLocale ran — which for a ticket status change
 * means the language of the *admin's* panel would decide the language of a word in
 * the *customer's* email.
 */
final class TicketLabels
{
    /** open | in_progress | closed, as a ticket state: "Deschis", "În lucru", "Închis". */
    public static function status(mixed $status): string
    {
        return self::translate($status, [
            'open' => 'Open',
            'in_progress' => 'In progress',
            'closed' => 'Closed',
        ]);
    }

    /**
     * low | normal | high | urgent, lower-case on purpose: the label lands after the
     * word "prioritate" in the body ("prioritate ridicată"), never on its own.
     */
    public static function priority(mixed $priority): string
    {
        return self::translate($priority, [
            'low' => 'Low',
            'normal' => 'Normal',
            'high' => 'High',
            'urgent' => 'Urgent',
        ]);
    }

    /**
     * An enum value we do not know falls through to the readable form of itself
     * rather than to an empty string — a value added to the column without being
     * added here should look unpolished in an email, not make the sentence vanish.
     *
     * @param  array<string, string>  $sources  stored value => English source string
     */
    private static function translate(mixed $value, array $sources): string
    {
        $value = is_string($value) ? trim($value) : '';

        if ($value === '') {
            return '';
        }

        if (! isset($sources[$value])) {
            return ucfirst(str_replace('_', ' ', $value));
        }

        $translated = __($sources[$value], [], 'ro');

        // __() hands back the key itself when lang/ro.json has no entry, which is the
        // right fallback; the is_string guard is only for its array return shape.
        return is_string($translated) ? $translated : $sources[$value];
    }
}
