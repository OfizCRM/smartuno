import { Link } from '@inertiajs/react';
import { isValidElement } from 'react';

/**
 * A quiet line across the top of a screen that suggests one thing.
 *
 * Not an alert and not a toast: nothing has gone wrong and nothing has just
 * happened. It is there for the case where the screen works but would work
 * better if one more thing were set up — a company profile that has not been
 * filled in, a validity period still on its default. So it is a coloured strip
 * with one action, and it never blocks what is underneath it.
 *
 * Amber is the default because that is the colour of "worth a look". Green says
 * something succeeded and coral says something is broken; neither is what this
 * is for, but both are here for the screens that do mean them.
 *
 * Props:
 *   tone        – 'accent' (default) | 'brand' | 'coral' | 'neutral'
 *   title       – the one sentence. Already translated.
 *   description – the detail underneath. Optional, already translated.
 *   action      – { label, href?, onClick?, method? }, or a ready-made element
 *                 when the caller needs something this shape cannot express.
 */

const TONES = {
    accent: {
        frame: 'border-accent-200 bg-accent-50 dark:border-accent-800/50 dark:bg-accent-900/20',
        title: 'text-accent-900 dark:text-accent-200',
        body: 'text-accent-800/80 dark:text-accent-200/70',
        action: 'bg-accent-500 text-accent-950 hover:bg-accent-400 dark:bg-accent-500 dark:text-accent-950 dark:hover:bg-accent-400',
    },
    brand: {
        frame: 'border-brand-200 bg-brand-50 dark:border-brand-800/50 dark:bg-brand-900/20',
        title: 'text-brand-900 dark:text-brand-200',
        body: 'text-brand-800/80 dark:text-brand-200/70',
        action: 'bg-brand-600 text-white hover:bg-brand-700 dark:bg-brand-500 dark:hover:bg-brand-600',
    },
    coral: {
        frame: 'border-coral-200 bg-coral-50 dark:border-coral-800/50 dark:bg-coral-900/20',
        title: 'text-coral-900 dark:text-coral-200',
        body: 'text-coral-800/80 dark:text-coral-200/70',
        action: 'bg-coral-600 text-white hover:bg-coral-700 dark:bg-coral-600 dark:hover:bg-coral-500',
    },
    neutral: {
        frame: 'border-neutral-200 bg-neutral-50 dark:border-neutral-700 dark:bg-neutral-800/50',
        title: 'text-neutral-900 dark:text-neutral-100',
        body: 'text-neutral-600 dark:text-neutral-400',
        action: 'bg-neutral-900 text-white hover:bg-neutral-700 dark:bg-neutral-100 dark:text-neutral-900 dark:hover:bg-white',
    },
};

export default function NudgeBanner({ tone = 'accent', title, description, action }) {
    const palette = TONES[tone] ?? TONES.accent;
    const button = 'inline-flex shrink-0 items-center justify-center rounded-lg px-3 py-1.5 text-[13px] font-semibold transition focus:outline-none focus:ring-2 focus:ring-brand-500/20 focus:ring-offset-1';

    return (
        <div className={`flex flex-wrap items-center gap-3 rounded-xl border px-4 py-3 ${palette.frame}`}>
            <div className="min-w-0 flex-1">
                {title && <p className={`text-sm font-semibold ${palette.title}`}>{title}</p>}
                {description && <p className={`mt-0.5 text-[13px] ${palette.body}`}>{description}</p>}
            </div>

            {isValidElement(action) ? action : action?.label && (
                action.href ? (
                    <Link href={action.href} method={action.method} className={`${button} ${palette.action}`}>
                        {action.label}
                    </Link>
                ) : (
                    <button type="button" onClick={action.onClick} className={`${button} ${palette.action}`}>
                        {action.label}
                    </button>
                )
            )}
        </div>
    );
}
