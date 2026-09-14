import { ref } from 'vue';
import { requestJson } from '@/lib/http';

/** Session S3 "How this works": the module whose help the current page offers (set by AppLayout's `help` prop), and the words, cached per language. */
export type HelpModule = 'quotes' | 'policies' | 'renewals' | 'receipts' | 'bank' | 'claims' | 'commission' | 'accounting' | 'close' | 'reports'
    // Gap fixes W7 (GA-30): the screens that had no help.
    | 'distribution' | 'refunds' | 'cheques' | 'agentcash' | 'tariffs' | 'users' | 'limits' | 'chart' | 'events'
    // UX consistency pass: reinsurance and regulatory screens.
    | 'reinsurance' | 'regulatory'
    // UI consistency pass: the finance modules.
    | 'payables' | 'assets' | 'budgets' | 'pettycash';
export interface HelpText {
    module: HelpModule;
    locale: 'en' | 'bn';
    title: string;
    html: string;
}

export const helpModule = ref<HelpModule | null>(null);

const cache = new Map<string, Promise<HelpText>>();

export function loadHelp(module: HelpModule, locale: 'en' | 'bn'): Promise<HelpText> {
    const key = `${module}.${locale}`;
    if (!cache.has(key)) {
        const request = requestJson<HelpText>('GET', `/help/${module}?locale=${locale}`);
        request.catch(() => cache.delete(key));
        cache.set(key, request);
    }
    return cache.get(key)!;
}
