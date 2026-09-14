/**
 * Gap fixes W7 (L5): the browser-side checks of a form speak the user's language. A user whose `locale` preference is Bangla saw Bangla help and risk labels,
 * but English messages from the forms' own checks and from the browser's constraint bubbles ("Please fill out this field."). The wording follows the
 * server's Bangla risk problems (App\Http\Feedback\RiskProblems, ASSUMPTION A-161) and docs/glossary.md, with Latin digits as on Bangla documents (A-104).
 * ASSUMPTION A-236: the Bangla sentences are ours, to verify with the customer.
 */
export type FormLocale = 'en' | 'bn';

type Words = { en: string; bn: string };
const say = (locale: FormLocale, words: Words): string => (locale === 'bn' ? words.bn : words.en);

/** The forms' own checks before they post (register a claim, a policy for an unrated product). */
export const formMessages = {
    choosePolicyForClaim: (l: FormLocale) => say(l, { en: 'Choose the policy the claim is on.', bn: 'যে পলিসিতে ক্লেইম, সেটি বেছে নিন।' }),
    enterLossDate: (l: FormLocale) => say(l, { en: 'Enter the date of loss.', bn: 'ক্ষতির তারিখ লিখুন।' }),
    lossOutsideCover: (l: FormLocale, from: string, to: string) => say(l, { en: `The loss must fall within the cover, ${from} to ${to}.`, bn: `ক্ষতির তারিখ কভারের মধ্যে হতে হবে, ${from} থেকে ${to}।` }),
    enterReportedOn: (l: FormLocale) => say(l, { en: 'Enter the date the loss was reported.', bn: 'ক্ষতি জানানোর তারিখ লিখুন।' }),
    reportedBeforeLoss: (l: FormLocale) => say(l, { en: 'A loss cannot be reported before it happened.', bn: 'ক্ষতি ঘটার আগে তা জানানো যায় না।' }),
    describeLoss: (l: FormLocale) => say(l, { en: 'Describe what happened in a sentence.', bn: 'কী ঘটেছে এক বাক্যে লিখুন।' }),
    choosePolicyholder: (l: FormLocale) => say(l, { en: 'Choose the policyholder, or press Ctrl+N to add a new customer.', bn: 'পলিসিহোল্ডার বেছে নিন, অথবা নতুন গ্রাহক যোগ করতে Ctrl+N চাপুন।' }),
    chooseProduct: (l: FormLocale) => say(l, { en: 'Choose the product to quote.', bn: 'যে পণ্যের কোট করবেন, সেটি বেছে নিন।' }),
    enterCoverStart: (l: FormLocale) => say(l, { en: 'Enter the date the cover starts.', bn: 'কভার শুরুর তারিখ লিখুন।' }),
    enterGrossPremium: (l: FormLocale, currency: string) => say(l, { en: `Enter the gross premium in ${currency}, like 120,000.00.`, bn: `মোট প্রিমিয়াম ${currency} এ লিখুন, যেমন 120,000.00।` }),
    payerShares: (l: FormLocale, total: string) => say(l, { en: `Payer shares add up to ${total}%; they must add up to 100%.`, bn: `প্রিমিয়াম প্রদানকারীদের অংশ মিলে ${total}%; মোট 100% হতে হবে।` }),
};

/** The constraint a browser found on a field, as `ValidityState` reports it, with the field's own limits. */
export interface ConstrainedField {
    validity: Pick<ValidityState, 'valueMissing' | 'typeMismatch' | 'patternMismatch' | 'tooLong' | 'tooShort' | 'rangeUnderflow' | 'rangeOverflow' | 'stepMismatch' | 'badInput'>;
    type?: string;
    min?: string;
    max?: string;
    maxLength?: number;
    minLength?: number;
}

/** The Bangla sentence for a browser constraint; empty in English, where the browser's own wording stays. */
export function nativeValidityMessage(field: ConstrainedField, locale: FormLocale): string {
    if (locale !== 'bn') return '';
    const v = field.validity;
    if (v.valueMissing) return field.type === 'checkbox' ? 'চালিয়ে যেতে বাক্সে টিক দিন।' : field.type === 'file' ? 'একটি ফাইল বেছে নিন।' : 'এই ঘরটি পূরণ করুন।';
    if (v.typeMismatch) return field.type === 'email' ? 'একটি পূর্ণ ইমেইল ঠিকানা লিখুন, @ ও তার পরের অংশসহ।' : field.type === 'url' ? 'একটি পূর্ণ ওয়েব ঠিকানা লিখুন।' : 'সঠিক ধরনে লিখুন।';
    if (v.badInput) return field.type === 'date' ? '15 Sep 2026-এর মতো একটি তারিখ লিখুন।' : 'একটি সংখ্যা লিখুন।';
    if (v.rangeUnderflow) return field.min ? `কমপক্ষে ${field.min} লিখুন।` : 'মানটি খুব কম।';
    if (v.rangeOverflow) return field.max ? `সর্বোচ্চ ${field.max} লিখুন।` : 'মানটি খুব বেশি।';
    if (v.stepMismatch) return 'একটি পূর্ণ সংখ্যা লিখুন।';
    if (v.tooLong) return `${field.maxLength ?? ''} অক্ষরের মধ্যে রাখুন।`.trim();
    if (v.tooShort) return `কমপক্ষে ${field.minLength ?? ''} অক্ষর লিখুন।`;
    if (v.patternMismatch) return 'চাওয়া ধরনে লিখুন।';
    return '';
}

type Control = HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement;
const isControl = (target: EventTarget | null): target is Control =>
    typeof HTMLElement !== 'undefined' && (target instanceof HTMLInputElement || target instanceof HTMLSelectElement || target instanceof HTMLTextAreaElement);

let installed: (() => void) | null = null;

/**
 * Words the browser's constraint bubbles in the user's language for every form on the page: when a field is found invalid its message is set from
 * nativeValidityMessage, and cleared as soon as the user edits it (so the browser checks it afresh). Installed once; returns the uninstaller.
 */
export function installLocalizedValidity(locale: () => FormLocale, root: Document = document): () => void {
    if (installed) return installed;
    const onInvalid = (event: Event): void => {
        if (!isControl(event.target)) return;
        const field = event.target;
        field.setCustomValidity('');
        const message = nativeValidityMessage(field as unknown as ConstrainedField, locale());
        if (message !== '') field.setCustomValidity(message);
    };
    const onEdit = (event: Event): void => {
        if (isControl(event.target)) event.target.setCustomValidity('');
    };
    root.addEventListener('invalid', onInvalid, true);
    root.addEventListener('input', onEdit, true);
    root.addEventListener('change', onEdit, true);
    installed = () => {
        root.removeEventListener('invalid', onInvalid, true);
        root.removeEventListener('input', onEdit, true);
        root.removeEventListener('change', onEdit, true);
        installed = null;
    };
    return installed;
}
