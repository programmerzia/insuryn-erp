# Accounting events

## What this screen is for
Every insurance action, such as issuing a policy or recording a receipt, sends an accounting event that the posting engine turns into a journal. This screen lists the events that did not post: those that failed, and those still waiting longer than they should.

## What happens in the accounting
An event that fails leaves the business record in place but nothing in the ledger, so the ledger and the registers disagree until it posts. The reason says what to fix first, such as an account role without an account or a month that is locked. After fixing the cause, Requeue posts the same event once; it is never posted twice.

## Next step
Requeue the fixed events, then rerun the reconciliations in *Month-end close*.
