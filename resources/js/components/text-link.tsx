import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import { ComponentProps } from 'react';

type LinkProps = ComponentProps<typeof Link>;

export default function TextLink({ className = '', children, ...props }: LinkProps) {
    return (
        <Link
            className={cn(
                'font-medium text-indigo-600 underline decoration-indigo-300/80 underline-offset-4 transition-colors duration-200 ease-out hover:text-indigo-700 hover:decoration-current! dark:text-indigo-400 dark:decoration-indigo-500/60 dark:hover:text-indigo-300',
                className,
            )}
            {...props}
        >
            {children}
        </Link>
    );
}
