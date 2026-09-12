/** Props of the read-only accounting pages (slice 0.6). Amounts are pre-formatted from minor units on the server. */

export interface EntityRef {
    id: string;
    code: string;
    name: string;
    currency: string;
}

export interface TrialBalanceRow {
    accountId: string;
    code: string;
    name: string;
    type: string;
    debit: string;
    credit: string;
    balance: string;
}

export interface JournalRef {
    id: string;
    number: string | null;
    status: string;
    kind: string;
}

export interface JournalListItem extends JournalRef {
    postingDate: string;
    description: string | null;
    total: string;
    currency: string;
}

export interface JournalLineView {
    lineNo: number;
    account: { code: string; name: string };
    side: 'debit' | 'credit';
    amount: string;
    role: string | null;
    memo: string | null;
}

export interface JournalDetail extends JournalRef {
    transactionDate: string;
    postingDate: string;
    effectiveDate: string;
    description: string | null;
    reason: string | null;
    currency: string;
    postedAt: string | null;
    postingRule: { code: string; version: number } | null;
    source: { type: string; id: string } | null;
    event: { id: string; type: string } | null;
    reverses: JournalRef | null;
    reversedBy: JournalRef | null;
    corrects: JournalRef | null;
    corrections: JournalRef[];
    lines: JournalLineView[];
}
