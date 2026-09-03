/**
 * Brand-aligned button. Variants: primary, secondary, ghost, danger, outline
 */
const variantClasses = {
    primary:
        'bg-brand-500 text-white border-transparent shadow-card hover:bg-brand-600 hover:shadow-soft-md active:shadow-inner dark:bg-brand-500 dark:hover:bg-brand-600 dark:text-white',
    secondary:
        'bg-warm-gray-100 text-warm-gray-900 border-transparent hover:bg-warm-gray-200 active:bg-warm-gray-200 dark:bg-neutral-800 dark:text-neutral-200 dark:border-neutral-700 dark:hover:bg-neutral-700 dark:active:bg-neutral-600',
    ghost:
        'bg-transparent text-warm-gray-900 border-transparent hover:bg-warm-gray-100 active:bg-warm-gray-200 dark:text-neutral-300 dark:hover:bg-neutral-800 dark:active:bg-neutral-700',
    danger:
        'bg-coral-500 text-white border-transparent shadow-card hover:bg-coral-600 hover:shadow-soft-md active:shadow-inner dark:bg-coral-600 dark:hover:bg-coral-500',
    outline:
        'bg-transparent text-warm-gray-900 border-warm-border hover:bg-warm-gray-50 active:bg-warm-gray-100 dark:text-neutral-300 dark:border-neutral-600 dark:hover:bg-neutral-800 dark:active:bg-neutral-700',
};

const sizeClasses = {
    sm: 'px-3 py-1.5 text-[13px] rounded-xl',
    md: 'px-4 py-2 text-[13px] rounded-xl',
    lg: 'px-5 py-2.5 text-sm rounded-xl',
};

export default function Button({
    type = 'button',
    variant = 'primary',
    size = 'md',
    disabled = false,
    className = '',
    children,
    ...props
}) {
    return (
        <button
            type={type}
            disabled={disabled}
            className={[
                'inline-flex items-center justify-center font-semibold border transition-all duration-150 ease-smooth focus:outline-none focus:ring-2 focus:ring-brand-500/20 focus:ring-offset-1 disabled:opacity-50 disabled:pointer-events-none',
                variantClasses[variant] ?? variantClasses.primary,
                sizeClasses[size] ?? sizeClasses.md,
                className,
            ].join(' ')}
            {...props}
        >
            {children}
        </button>
    );
}
