import { ref } from 'vue';
import { requestJson } from '@/lib/http';

/** Session S3 "How this works": the module whose help the current page offers (set by AppLayout's `help` prop), and the words, cached per language. */
export type HelpModule = 'quotes' | 'policies' | 'renewals' | 'receipts' | 'bank' | 'claims' | 'commission' | 'accounting' | 'close' | 'reports';
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
