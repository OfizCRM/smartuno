/**
 * One sentence describing a conversation event.
 *
 * Extracted from the inbox, where it has always lived, so the contact record can
 * say the same thing. The record first shipped with a half-map that named the
 * types and never filled the placeholders, so the page printed literal
 * "{{actor}} a adăugat eticheta {{label}}" — and 'handover', the most common
 * event of all, fell through to the raw type name.
 *
 * `entry` is either an activity row from the inbox (with a loaded `user`) or the
 * flattened shape the contact record fetches (with `actor` already a name).
 */
export function activityText(entry, t) {
    const actor = entry.user?.name || entry.actor || t('inbox.activity_system');
    const meta = entry.meta || {};
    const status = (s) => (s ? t(`inbox.status_${s}`) : '—');

    switch (entry.type) {
        case 'created':
            // No actor means the customer opened it by writing in.
            return (entry.user || entry.actor)
                ? t('inbox.activity_created', { actor })
                : t('inbox.activity_conversation_started');
        case 'assigned':
            return t('inbox.activity_assigned', { actor, agent: meta.to_name || '—' });
        case 'transferred':
            return t('inbox.activity_transferred', { actor, from: meta.from_name || '—', to: meta.to_name || '—' });
        case 'unassigned':
            return t('inbox.activity_unassigned', { actor, agent: meta.from_name || '—' });
        case 'status_changed':
            return t('inbox.activity_status_changed', { actor, from: status(meta.from), to: status(meta.to) });
        case 'handover':
            return meta.to === 'human'
                ? t('inbox.activity_handover_human', { actor })
                : t('inbox.activity_handover_bot', { actor });
        case 'label_added':
            return t('inbox.activity_label_added', { actor, label: meta.label || '—' });
        case 'label_removed':
            return t('inbox.activity_label_removed', { actor, label: meta.label || '—' });
        default:
            return entry.type;
    }
}
