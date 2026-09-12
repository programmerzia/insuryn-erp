import { clsx, type ClassValue } from 'clsx';
import { twMerge } from 'tailwind-merge';

/** shadcn-vue class merge helper: later Tailwind utilities win over earlier conflicting ones. */
export function cn(...inputs: ClassValue[]): string {
    return twMerge(clsx(inputs));
}
