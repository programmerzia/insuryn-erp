import { describe, expect, it } from 'vitest';
import { accessRequestHref } from '@/lib/errorPage';
import { sodConflicts } from '@/lib/sod';
import { statusTone } from '@/lib/status';

describe('ask for access on a refused page (GA-07)', () => {
    it('writes to the administrators with the page and what it needs', () => {
        const href = accessRequestHref({ admins: [{ name: 'Nasrin', email: 'admin@nonlife.local' }, { name: 'Karim', email: 'karim@nonlife.local' }], subject: 'Access request: Manage roles' },
            'http://nonlife.localhost/admin/roles');
        expect(href).toBe('mailto:admin@nonlife.local,karim@nonlife.local?subject=Access%20request%3A%20Manage%20roles&body=Please%20give%20me%20access%20to%20http%3A%2F%2Fnonlife.localhost%2Fadmin%2Froles.');
    });

    it('offers nothing when no administrator is known', () => {
        expect(accessRequestHref({ admins: [], subject: 'Access request: /bank' }, 'http://x/bank')).toBeNull();
    });
});

describe('segregation of duties notice on a role (GA-22)', () => {
    const rules = [
        { a: 'receipt.refund_request', b: 'receipt.refund_release', mode: 'block' },
        { a: 'platform.manage_roles', b: 'accounting.*', mode: 'block' },
        { a: 'commission.approve', b: 'commission.pay', mode: 'warn' },
    ];

    it('names each pair of ticked permissions a rule keeps apart, wildcards included', () => {
        expect(sodConflicts(['receipt.refund_request', 'receipt.refund_release', 'policy.issue'], rules)).toEqual([{ a: 'receipt.refund_request', b: 'receipt.refund_release', mode: 'block' }]);
        expect(sodConflicts(['platform.manage_roles', 'accounting.view_journals', 'accounting.requeue_event'], rules)).toEqual([
            { a: 'platform.manage_roles', b: 'accounting.view_journals', mode: 'block' },
            { a: 'platform.manage_roles', b: 'accounting.requeue_event', mode: 'block' },
        ]);
        expect(sodConflicts(['commission.approve', 'commission.pay'], rules)).toEqual([{ a: 'commission.approve', b: 'commission.pay', mode: 'warn' }]);
    });

    it('is quiet when only one side is ticked', () => {
        expect(sodConflicts(['receipt.refund_request', 'accounting.view_journals'], rules)).toEqual([]);
    });

    it('shows an open invitation as waiting', () => {
        expect(statusTone('invited')).toBe('warn');
    });
});
