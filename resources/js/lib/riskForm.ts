import { formatMinor, parseMoney } from '@/lib/money';

/**
 * Phase 3 slice R4 quote workbench: the risk form is generated from the product version's risk schema (text, integer, money, date, select, boolean with
 * EN/BN labels, required marks, min/max), values typed by the officer become the risk inputs the server rates (integers as numbers, money as minor units
 * in a digit string — never a float), and the rating result's explanation lines are shown in the user's language. Pure functions, no Vue.
 */
export type Locale = 'en' | 'bn';
export type RiskFieldType = 'text' | 'integer' | 'money' | 'date' | 'select' | 'boolean';
/** Flow fix X7: a required field is needed to rate (`quote`) or only when the proposal is submitted (`proposal`). */
export type RiskStage = 'quote' | 'proposal';

export interface RiskFieldDefinition {
    key: string;
    label_en: string;
    label_bn: string;
    type: RiskFieldType;
    required: boolean;
    options?: { value: string; label_en: string; label_bn: string }[];
    min?: number;
    max?: number;
    max_length?: number;
    /** Flow fix X7: absent means `quote`. */
    required_at?: RiskStage;
    /** Flow fix X7: the value a new quote form starts with (money in minor units). */
    default?: string | number | boolean;
}

export interface FormField {
    key: string;
    label: string;
    type: RiskFieldType;
    required: boolean;
    options: { value: string; label: string }[];
    hint: string | null;
    maxLength: number | null;
}

export interface ProductVersionOption {
    id: string;
    version: number;
    effective_from: string;
    effective_to: string | null;
    class_code: string;
    risk_schema: RiskFieldDefinition[];
    coverages: { code: string; name_en: string; name_bn: string; mandatory: boolean }[];
}

export interface ExplanationLine {
    step_code: string;
    kind: string;
    label_en: string;
    label_bn: string;
    amount_minor: number;
    running_total_minor: number;
}

export interface RatingResultData {
    currency: string;
    as_of: string;
    plan: { id: string | null; code: string; version: number; class_code: string };
    net_premium_minor: number;
    duties: { code: string; label_en: string; label_bn: string; amount_minor: number }[];
    duties_total_minor: number;
    gross_premium_minor: number;
    explanation: ExplanationLine[];
    risk_inputs: Record<string, string | number | boolean | null>;
    coverages: string[];
    verify: boolean;
}

/** Form values as typed: strings for every input, booleans for checkboxes. */
export type FormValues = Record<string, string | boolean>;

const label = (item: { label_en: string; label_bn: string }, locale: Locale) => (locale === 'bn' ? item.label_bn : item.label_en) || item.label_en;
const group = (value: number) => value.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');

/** The product version in force on the cover start: effective_from ≤ day < effective_to. */
export function versionOn(versions: ProductVersionOption[], day: string): ProductVersionOption | null {
    return versions.find((v) => v.effective_from <= day && (v.effective_to === null || day < v.effective_to)) ?? null;
}

/** Whether the field must hold a value at the stage (flow fix X7): a quote-stage field always, a proposal-stage field only for the proposal. */
export function requiredFor(field: RiskFieldDefinition, stage: RiskStage): boolean {
    return field.required && ((field.required_at ?? 'quote') === 'quote' || stage === 'proposal');
}

/** The hint under a field needed only for the proposal, shown on the quote form. */
export const PROPOSAL_STAGE_HINT = 'Needed when the proposal is submitted, not for the price.';

export function formFields(schema: RiskFieldDefinition[], locale: Locale, stage: RiskStage = 'quote'): FormField[] {
    return schema.map((field) => {
        const bounds = boundsHint(field);
        const later = stage === 'quote' && field.required && !requiredFor(field, 'quote');
        return {
            key: field.key,
            label: label(field, locale),
            type: field.type,
            required: requiredFor(field, stage),
            options: (field.options ?? []).map((o) => ({ value: o.value, label: label(o, locale) })),
            hint: later ? (bounds ? `${bounds}. ${PROPOSAL_STAGE_HINT}` : PROPOSAL_STAGE_HINT) : bounds,
            maxLength: field.max_length ?? null,
        };
    });
}

/**
 * "50 to 10,000", "At least 1,000.00", "Up to 60" — money bounds are minor units shown in major units. Gap fixes W7 (GA-25): a money minimum of one poisha only
 * says the amount must be positive, and "At least 0.01" on a sum insured reads oddly, so it gives no hint.
 */
export function boundsHint(field: RiskFieldDefinition): string | null {
    if (field.type !== 'integer' && field.type !== 'money') return null;
    const show = (n: number) => (field.type === 'money' ? formatMinor(BigInt(n)) : field.key.startsWith('year') ? String(n) : group(n));
    const min = field.type === 'money' && field.min !== undefined && field.min <= 1 ? undefined : field.min;
    if (min !== undefined && field.max !== undefined) return `${show(min)} to ${show(field.max)}`;
    if (min !== undefined) return `At least ${show(min)}`;
    if (field.max !== undefined) return `Up to ${show(field.max)}`;
    return null;
}

/**
 * Stored risk inputs (a saved quotation) → form values: money minor units become "1,234,567.00". A new form (no inputs at all) starts from the schema's
 * defaults (flow fix X7); a saved quotation keeps what was saved.
 */
export function initialValues(schema: RiskFieldDefinition[], inputs: Record<string, unknown> | null | undefined): FormValues {
    const values: FormValues = {};
    for (const field of schema) {
        const raw = inputs == null ? field.default : inputs[field.key];
        if (field.type === 'boolean') {
            values[field.key] = raw === true || raw === 'true' || raw === 1 || raw === '1';
        } else if (field.type === 'money' && (typeof raw === 'number' || (typeof raw === 'string' && /^\d+$/.test(raw)))) {
            values[field.key] = formatMinor(BigInt(raw));
        } else {
            values[field.key] = raw === null || raw === undefined ? '' : String(raw);
        }
    }
    return values;
}

/**
 * Form values → risk inputs for the server. Empty fields are left out (the server says REQUIRED); integers that are whole numbers become numbers,
 * money becomes minor units as a digit string; text that is not a number is sent as typed so the server names the problem.
 */
export function riskInputs(schema: RiskFieldDefinition[], values: FormValues): Record<string, string | number | boolean> {
    const inputs: Record<string, string | number | boolean> = {};
    for (const field of schema) {
        const value = values[field.key];
        if (field.type === 'boolean') {
            inputs[field.key] = value === true;
            continue;
        }
        const text = typeof value === 'string' ? value.trim() : '';
        if (text === '') continue;
        if (field.type === 'integer') {
            const digits = text.replaceAll(',', '');
            inputs[field.key] = /^-?\d{1,15}$/.test(digits) ? Number(digits) : text;
        } else if (field.type === 'money') {
            const minor = parseMoney(text);
            inputs[field.key] = minor === null || minor < 0n ? text : minor.toString();
        } else {
            inputs[field.key] = text;
        }
    }
    return inputs;
}

/**
 * Checks in the browser before rating (the server checks again): required, whole numbers, bounds, options, dates, length. Field key → message, in the user's
 * language (gap fixes W7, L5).
 */
export function localProblems(schema: RiskFieldDefinition[], values: FormValues, stage: RiskStage = 'quote', locale: Locale = 'en'): Record<string, string> {
    const problems: Record<string, string> = {};
    const inputs = riskInputs(schema, values);
    for (const field of schema) {
        const value = inputs[field.key];
        if (value === undefined) {
            if (requiredFor(field, stage)) problems[field.key] = problemMessage('REQUIRED', field, locale);
            continue;
        }
        const code = problemCode(field, value);
        if (code) problems[field.key] = problemMessage(code, field, locale);
    }
    return problems;
}

function problemCode(field: RiskFieldDefinition, value: string | number | boolean): string | null {
    switch (field.type) {
        case 'integer':
        case 'money': {
            const number = field.type === 'money' ? (typeof value === 'string' && /^\d+$/.test(value) ? BigInt(value) : null) : typeof value === 'number' ? BigInt(value) : null;
            if (number === null) return 'NOT_INTEGER';
            if (field.min !== undefined && number < BigInt(field.min)) return 'BELOW_MIN';
            if (field.max !== undefined && number > BigInt(field.max)) return 'ABOVE_MAX';
            return null;
        }
        case 'select':
            return (field.options ?? []).some((o) => o.value === value) ? null : 'NOT_AN_OPTION';
        case 'date':
            return typeof value === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(value) && !Number.isNaN(Date.parse(`${value}T00:00:00Z`)) ? null : 'NOT_A_DATE';
        case 'text':
            return typeof value === 'string' && value.length > (field.max_length ?? 255) ? 'TOO_LONG' : null;
        default:
            return null;
    }
}

/**
 * Follow-up H2: the problems of a RISK_INPUTS_INVALID refusal per field. The server words them with the schema's labels in the user's language (`fields`);
 * a code without a sentence falls back to the browser's own wording.
 */
export function serverProblems(body: { errors?: Record<string, string | string[]>; fields?: Record<string, string> } | null, schema: RiskFieldDefinition[], locale: Locale = 'en'): Record<string, string> {
    return Object.fromEntries(Object.entries(body?.errors ?? {}).map(([k, code]) => [k, body?.fields?.[k] ?? problemMessage(String(code), schema.find((f) => f.key === k), locale)]));
}

/**
 * A risk schema problem code (from the browser or the server) in words for the field. Gap fixes W7 (L5): in Bangla for a Bangla user, the same sentences the
 * server words (App\Http\Feedback\RiskProblems, A-161), with the field's Bangla label.
 */
export function problemMessage(code: string, field: RiskFieldDefinition | undefined, locale: Locale = 'en'): string {
    const show = (n: number | undefined) => (n === undefined || !field ? '' : field.type === 'money' ? formatMinor(BigInt(n)) : field.key.startsWith('year') ? String(n) : group(n));
    if (locale === 'bn') return banglaProblem(code, field, show);
    switch (code) {
        case 'REQUIRED':
            return field?.type === 'select' ? `Choose the ${field.label_en.toLowerCase()}.` : `Enter the ${(field?.label_en ?? 'value').toLowerCase()}.`;
        case 'NOT_INTEGER':
            return field?.type === 'money' ? 'Enter an amount, like 1,234,567.00.' : 'Enter a whole number.';
        case 'BELOW_MIN':
            return field?.min === undefined ? 'The value is too low.' : `Enter at least ${show(field.min)}.`;
        case 'ABOVE_MAX':
            return field?.max === undefined ? 'The value is too high.' : `Enter at most ${show(field.max)}.`;
        case 'NOT_AN_OPTION':
            return 'Choose one of the options.';
        case 'NOT_A_DATE':
            return 'Enter a date like 15 Sep 2026.';
        case 'TOO_LONG':
            return `Keep it to ${field?.max_length ?? 255} characters.`;
        case 'UNKNOWN_FIELD':
            return 'This product does not ask for this.';
        case 'NOT_BOOLEAN':
            return 'Tick or clear the box.';
        default:
            return 'Check this value.';
    }
}

function banglaProblem(code: string, field: RiskFieldDefinition | undefined, show: (n: number | undefined) => string): string {
    const label = field?.label_bn ?? 'মান';
    switch (code) {
        case 'REQUIRED':
            return field?.type === 'select' ? `${label} বেছে নিন।` : `${label} লিখুন।`;
        case 'NOT_INTEGER':
            return field?.type === 'money' ? 'টাকার অঙ্ক লিখুন, যেমন 1,234,567.00।' : 'একটি পূর্ণ সংখ্যা লিখুন।';
        case 'BELOW_MIN':
            return field?.min === undefined ? 'মানটি খুব কম।' : `কমপক্ষে ${show(field.min)} লিখুন।`;
        case 'ABOVE_MAX':
            return field?.max === undefined ? 'মানটি খুব বেশি।' : `সর্বোচ্চ ${show(field.max)} লিখুন।`;
        case 'NOT_AN_OPTION':
            return 'তালিকা থেকে একটি বেছে নিন।';
        case 'NOT_A_DATE':
            return '15 Sep 2026-এর মতো একটি তারিখ লিখুন।';
        case 'TOO_LONG':
            return `${field?.max_length ?? 255} অক্ষরের মধ্যে রাখুন।`;
        case 'UNKNOWN_FIELD':
            return 'এই পণ্যে এই তথ্য লাগে না।';
        case 'NOT_BOOLEAN':
            return 'বাক্সে টিক দিন বা টিক তুলে দিন।';
        default:
            return 'মানটি যাচাই করুন।';
    }
}

/** The breakdown on the right rail: each explanation line in the user's language with its amount in major units. */
export function breakdown(result: RatingResultData, locale: Locale): { code: string; kind: string; label: string; amount: string; running: string }[] {
    return result.explanation.map((line) => ({
        code: line.step_code,
        kind: line.kind,
        label: label(line, locale),
        amount: formatMinor(BigInt(line.amount_minor)),
        running: formatMinor(BigInt(line.running_total_minor)),
    }));
}

/** A stable key for the inputs, so the workbench rates again only when something that affects the premium changed. */
export function ratingKey(parts: { product_id: string; inception: string; risk_inputs: Record<string, unknown>; coverages: string[] }): string {
    const sorted = Object.keys(parts.risk_inputs).sort().map((k) => [k, parts.risk_inputs[k]]);
    return JSON.stringify([parts.product_id, parts.inception, sorted, [...parts.coverages].sort()]);
}
