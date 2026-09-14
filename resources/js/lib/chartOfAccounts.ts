/**
 * UX U2: Accounting → Chart of accounts. Pure helpers for the screen: what an account posts as, the parents an account may move under (never itself or
 * one of its own children), what an edit may no longer change once journal lines name the account (A-167), and the drawer's starting values.
 */
import { normalSideFor } from '@/lib/accountCreate';

export interface ChartAccount {
    id: string;
    code: string;
    name: string;
    type: string;
    normal_side: 'debit' | 'credit' | string;
    parent_id: string | null;
    parent_code: string | null;
    depth: number;
    is_postable: boolean;
    is_control: boolean;
    control_subledger: string | null;
    currency: string | null;
    status: string;
    roles: { code: string; description: string }[];
    has_lines: boolean;
    balance_minor: number;
}

export interface AccountDraft {
    code: string;
    name: string;
    type: string;
    normal_side: string;
    parent_id: string;
    is_postable: boolean;
    is_control: boolean;
    control_subledger: string;
    currency: string;
}

/** "Postable", "Heading" (groups others, no postings) or "Control · premium subledger". */
export function postingLabel(account: Pick<ChartAccount, 'is_postable' | 'is_control' | 'control_subledger'>): string {
    if (account.is_control) return `Control · ${account.control_subledger ?? 'no'} subledger`;
    return account.is_postable ? 'Postable' : 'Heading';
}

/** Ids of the account and everything under it. */
export function descendantsOf(accounts: ChartAccount[], id: string): Set<string> {
    const found = new Set<string>([id]);
    let grew = true;
    while (grew) {
        grew = false;
        for (const account of accounts) {
            if (account.parent_id !== null && found.has(account.parent_id) && !found.has(account.id)) {
                found.add(account.id);
                grew = true;
            }
        }
    }
    return found;
}

/** Parent choices in tree order, indented, leaving out the edited account and its children. */
export function parentOptions(accounts: ChartAccount[], editing: string | null = null): { value: string; label: string }[] {
    const excluded = editing === null ? new Set<string>() : descendantsOf(accounts, editing);
    return accounts
        .filter((a) => !excluded.has(a.id))
        .map((a) => ({ value: a.id, label: `${' '.repeat(a.depth)}${a.code} · ${a.name}${a.status === 'active' ? '' : ' (inactive)'}` }));
}

/** What an edit keeps as it is (A-167): type and normal side once any journal line names the account, and postability when it is postable. */
export function editLocks(account: Pick<ChartAccount, 'has_lines' | 'is_postable'>): { typeAndSide: boolean; postable: boolean } {
    return { typeAndSide: account.has_lines, postable: account.has_lines && account.is_postable };
}

/** Why deactivating is not offered, as the screen says it; null when the server may accept it (it checks the rest: A-168). */
export function deactivateBlocker(account: ChartAccount): string | null {
    if (account.status !== 'active') return null;
    if (account.balance_minor !== 0) return 'It has a balance. Move the balance to another account with a journal first.';
    if (account.roles.length > 0) return `The accounting posts “${account.roles[0]!.description}” to it. Map that role to another account first.`;
    return null;
}

/** A new account's starting values: an expense on the debit side, or a child of `parent` sharing its type and side. */
export function newAccountDraft(parent: ChartAccount | null = null): AccountDraft {
    const type = parent?.type ?? 'expense';
    return { code: '', name: '', type, normal_side: parent?.normal_side ?? normalSideFor(type), parent_id: parent?.id ?? '', is_postable: true, is_control: false, control_subledger: '', currency: '' };
}
