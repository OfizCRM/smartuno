/**
 * Card with soft shadow and optional padding.
 */
export default function Card({
    padding = true,
    className = '',
    children,
    ...props
}) {
    return (
        <div
            className={[
                'rounded-2xl border border-warm-border dark:border-neutral-800 bg-white dark:bg-neutral-900 text-warm-gray-900 dark:text-neutral-100 shadow-card transition-shadow duration-150 hover:shadow-soft-md dark:shadow-none',
                padding && 'p-5',
                className,
            ].filter(Boolean).join(' ')}
            {...props}
        >
            {children}
        </div>
    );
}

Card.Header = function CardHeader({ title, action, className = '' }) {
    return (
        <div className={`mb-4 flex items-center justify-between ${className}`}>
            <h3 className="text-base font-semibold text-neutral-900 dark:text-neutral-100">{title}</h3>
            {action}
        </div>
    );
};

Card.Body = function CardBody({ className = '', ...props }) {
    return <div className={className} {...props} />;
};
