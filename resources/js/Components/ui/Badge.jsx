/**
 * Badge for status, count, or label. Variants: default, success, warning, danger, brand.
 */
const variantClasses = {
    default: 'bg-warm-gray-100 text-warm-gray-700 border-transparent dark:bg-neutral-800 dark:text-neutral-300 dark:border-neutral-700',
    success: 'bg-brand-100 text-brand-700 border-transparent dark:bg-brand-900/30 dark:text-brand-300 dark:border-brand-700',
    warning: 'bg-[#FFFBEB] text-[#92400E] border-transparent dark:bg-amber-900/30 dark:text-amber-300 dark:border-amber-700',
    danger: 'bg-[#FDF2F2] text-[#991B1B] border-transparent dark:bg-coral-950/40 dark:text-coral-300 dark:border-coral-800',
    brand: 'bg-brand-50 text-brand-700 border-transparent dark:bg-brand-900/30 dark:text-brand-300 dark:border-brand-700',
};

const sizeClasses = {
    sm: 'px-2 py-0.5 text-[10px]',
    md: 'px-2.5 py-1 text-[11px]',
    lg: 'px-3 py-1.5 text-xs',
};

export default function Badge({
    variant = 'default',
    size = 'md',
    className = '',
    children,
    ...props
}) {
    return (
        <span
            className={[
                'inline-flex items-center font-semibold rounded-full border transition-colors duration-150',
                variantClasses[variant] ?? variantClasses.default,
                sizeClasses[size] ?? sizeClasses.md,
                className,
            ].join(' ')}
            {...props}
        >
            {children}
        </span>
    );
}
