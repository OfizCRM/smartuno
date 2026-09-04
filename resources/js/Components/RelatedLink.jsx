import { Link } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';

/**
 * A small inline link to a page the current one depends on or leads to.
 *
 * The sidebar used to list every screen, so a feature that needed another one
 * could rely on the menu. With a nine-item sidebar it cannot: a campaign that
 * refuses to send without an approved WhatsApp template has to say where those
 * live, or the job dead-ends. Renders nothing when the route is unknown, so a
 * module that is not installed cannot break the page.
 */
export default function RelatedLink({ routeName, params, label, className = '' }) {
    let href;
    try {
        href = params ? route(routeName, params) : route(routeName);
    } catch {
        return null;
    }

    return (
        <Link
            href={href}
            className={[
                'inline-flex items-center gap-1 text-[13px] font-medium text-brand-600 transition-colors duration-150 hover:text-brand-700 dark:text-brand-400 dark:hover:text-brand-300',
                className,
            ].join(' ')}
        >
            {label}
            <ArrowRight className="h-3.5 w-3.5" />
        </Link>
    );
}
