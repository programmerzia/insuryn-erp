/** Slice 2.1b (D-55): a document dated in a period that still waits for approval, release or posting (ClosePageController::pending). */
export interface PendingDocument {
    type: string;
    id: string;
    label: string;
    date: string;
    status: string;
    cleared_by: string;
    amount: string | null;
    link: string | null;
    movable: boolean;
    can_move: boolean;
}

/** "1 document dated in September 2026 is still waiting" / "3 documents … are still waiting". */
export function pendingHeadline(count: number, month: string): string {
    return count === 1 ? `1 document dated in ${month} is still waiting` : `${count} documents dated in ${month} are still waiting`;
}
