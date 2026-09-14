/**
 * Flow fix X10: an account created inline from a manual journal line. The normal side follows the account type (assets and expenses on the debit side,
 * liabilities, equity and income on the credit side) as a suggestion the accountant can change; the account is added to the line's choices. Pure functions.
 */
export type AccountType = 'asset' | 'liability' | 'equity' | 'income' | 'expense';

export const ACCOUNT_TYPES: { value: AccountType; label: string }[] = [
    { value: 'asset', label: 'Asset' },
    { value: 'liability', label: 'Liability' },
    { value: 'equity', label: 'Equity' },
    { value: 'income', label: 'Income' },
    { value: 'expense', label: 'Expense' },
];

/** Who to ask when the account is not in the chart (the role template holding accounting.manage_coa). */
export const ASK_FOR_ACCOUNT = 'An account that is not in the chart is added by the Finance Manager.';

/** UX U2: where the whole chart is kept (every account, headings, deactivation). */
export const CHART_OF_ACCOUNTS_HREF = '/accounting/chart-of-accounts';

export function normalSideFor(type: string): 'debit' | 'credit' {
    return type === 'asset' || type === 'expense' ? 'debit' : 'credit';
}

export interface AccountChoice { id: string; code: string; name: string; is_control: boolean }

/** The line's account choices with a new account in code order (no duplicate when it is already there). */
export function withAccount(accounts: AccountChoice[], account: AccountChoice): AccountChoice[] {
    return [...accounts.filter((a) => a.id !== account.id), account].sort((a, b) => a.code.localeCompare(b.code, undefined, { numeric: true }));
}
