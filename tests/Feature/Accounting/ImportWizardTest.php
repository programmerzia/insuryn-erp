<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Accounting\Domain\Models\Journal;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;

/**
 * Spec §7 Import: upload → parse → validate → errors → dry-run → preview → commit; "never silently create
 * imbalance". COA CSV creates accounts (and role mappings); opening balances become one kind=opening journal
 * that goes through the manual-journal approval flow (maker ≠ checker).
 */
beforeEach(function (): void {
    $this->ctx = seedDemoTenant();
    $this->controller = importUser($this->ctx['tenant_id'], ['accounting.manage_coa', 'accounting.create_manual_journal', 'accounting.post_to_control', 'accounting.view_journals']);
    $this->viewer = importUser($this->ctx['tenant_id'], ['accounting.view_journals']);
    $this->headers = ['X-Tenant' => $this->ctx['tenant_id']];
});

/** @param list<string> $permissions */
function importUser(string $tenantId, array $permissions): User
{
    return asTenant($tenantId, fn (): User => User::query()->findOrFail(userWithPermissions($tenantId, $permissions)));
}

function csv(string $contents): UploadedFile
{
    return UploadedFile::fake()->createWithContent('import.csv', $contents);
}

/** @return array{accounts: int, mappings: int, journals: int, lines: int, audit: int, approvals: int} */
function importFootprint(string $tenantId): array
{
    return asTenant($tenantId, fn (): array => [
        'accounts' => DB::table('accounts')->count(), 'mappings' => DB::table('account_role_mappings')->count(),
        'journals' => DB::table('journals')->count(), 'lines' => DB::table('journal_lines')->count(),
        'audit' => DB::table('audit_events')->count(), 'approvals' => DB::table('approvals')->count(),
    ]);
}

const VALID_COA = "code,name,type,normal_side,parent_code,is_postable,is_control,control_subledger,role\n"
    ."6000,Operating Expenses,expense,debit,,false,false,,\n"
    ."6100,Office Rent,expense,debit,6000,true,false,,\n"
    ."1300,Agent Deposits Receivable,asset,debit,,true,true,agent,\n";

it('validates a chart of accounts and reports every invalid row without writing', function (): void {
    $before = importFootprint($this->ctx['tenant_id']);
    $invalid = "code,name,type,normal_side,parent_code,is_postable,is_control,control_subledger,role\n"
        ."7000,Bad Type,liabilities,credit,,true,false,,\n"      // row 2: unknown type
        ."1010,Duplicate Of Existing,asset,debit,,true,false,,\n" // row 3: code exists in the entity
        ."7100,Orphan,expense,debit,9999,true,false,,\n"          // row 4: unknown parent
        ."7200,Control Without Subledger,asset,debit,,true,true,,\n" // row 5: control needs a subledger
        ."7100,Repeated Code,expense,sideways,,maybe,false,,\n"   // row 6: repeated code, bad side, bad boolean
        ."7300,Unknown Role,expense,debit,,true,false,,no_such_role\n"; // row 7

    $response = actingAs($this->controller)->post('/api/accounting/imports/chart-of-accounts', ['file' => csv($invalid), 'mode' => 'validate'], $this->headers + ['Accept' => 'application/json']);

    $response->assertStatus(422)->assertJsonPath('valid', false);
    /** @var list<array{row: int, field: string, message: string}> $reported */
    $reported = $response->json('errors');
    $errors = array_map(fn (array $e): string => $e['row'].':'.$e['field'], $reported);
    expect($errors)->toContain('2:type', '3:code', '4:parent_code', '5:control_subledger', '6:code', '6:normal_side', '6:is_postable', '7:role')
        ->and(importFootprint($this->ctx['tenant_id']))->toBe($before);
});

it('previews a valid chart of accounts on dry-run and writes nothing', function (): void {
    $before = importFootprint($this->ctx['tenant_id']);

    actingAs($this->controller)->post('/api/accounting/imports/chart-of-accounts', ['file' => csv(VALID_COA), 'mode' => 'dry_run'], $this->headers + ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('valid', true)
        ->assertJsonPath('preview.accounts_to_create', 3)
        ->assertJsonPath('preview.rows.1.code', '6100')
        ->assertJsonPath('result', null);

    expect(importFootprint($this->ctx['tenant_id']))->toBe($before);
});

it('commits a valid chart of accounts with parents, controls and an audit row', function (): void {
    actingAs($this->controller)->post('/api/accounting/imports/chart-of-accounts', ['file' => csv(VALID_COA), 'mode' => 'commit'], $this->headers + ['Accept' => 'application/json'])
        ->assertOk()->assertJsonPath('result.accounts_created', 3);

    asTenant($this->ctx['tenant_id'], function (): void {
        $rent = DB::table('accounts')->where('code', '6100')->first();
        $parent = DB::table('accounts')->where('code', '6000')->first();
        expect($rent?->parent_id)->toBe($parent?->id)
            ->and((bool) $parent?->is_postable)->toBeFalse()
            ->and(DB::table('accounts')->where('code', '1300')->value('control_subledger'))->toBe('agent')
            ->and(DB::table('audit_events')->where('action', 'chart_of_accounts.imported')->value('permission'))->toBe('accounting.manage_coa');
    });
});

it('rejects unbalanced or invalid opening balances and writes nothing', function (): void {
    $before = importFootprint($this->ctx['tenant_id']);
    $invalid = "account_code,debit,credit,branch_code,memo\n"
        ."1010,1000.00,,HO,bank\n"         // row 2 ok
        ."9999,,500.00,HO,unknown\n"        // row 3: unknown account
        ."3100,10.5.0,,HO,bad amount\n"     // row 4: malformed amount
        ."4100,5.00,5.00,HO,both sides\n"   // row 5: both sides
        ."2100,,999.99,XX,branch\n";        // row 6: unknown branch; file does not balance

    $response = actingAs($this->controller)->post('/api/accounting/imports/opening-balances',
        ['file' => csv($invalid), 'mode' => 'dry_run', 'opening_date' => '2026-07-01'], $this->headers + ['Accept' => 'application/json']);

    $response->assertStatus(422);
    /** @var list<array{row: int, field: string, message: string}> $reported */
    $reported = $response->json('errors');
    $errors = array_map(fn (array $e): string => $e['row'].':'.$e['field'], $reported);
    expect($errors)->toContain('3:account_code', '4:debit', '5:credit', '6:branch_code', '0:balance')
        ->and(importFootprint($this->ctx['tenant_id']))->toBe($before);
});

it('dry-runs opening balances with totals and writes nothing, then commits them as an opening journal awaiting approval', function (): void {
    $file = "account_code,debit,credit,branch_code,memo\n"
        ."1010,250000.00,,HO,Bank balance\n"
        ."1100,49999.99,,HO,Receivables brought forward\n"
        ."3100,,300000.00,,Retained earnings\n"
        ."2100,,-0.01,,rounding written as negative credit\n"; // negative amounts are refused
    $before = importFootprint($this->ctx['tenant_id']);

    actingAs($this->controller)->post('/api/accounting/imports/opening-balances', ['file' => csv($file), 'mode' => 'dry_run', 'opening_date' => '2026-07-01'], $this->headers + ['Accept' => 'application/json'])
        ->assertStatus(422)->assertJsonPath('errors.0.row', 5);

    $balanced = "account_code,debit,credit,branch_code,memo\n"
        ."1010,250000.00,,HO,Bank balance\n"
        ."1100,49999.99,,HO,Receivables brought forward\n"
        ."3100,,299999.99,,Retained earnings\n";

    actingAs($this->controller)->post('/api/accounting/imports/opening-balances', ['file' => csv($balanced), 'mode' => 'dry_run', 'opening_date' => '2026-07-01'], $this->headers + ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('preview.lines', 3)
        ->assertJsonPath('preview.total_debit', '299,999.99')
        ->assertJsonPath('preview.total_credit', '299,999.99');
    expect(importFootprint($this->ctx['tenant_id']))->toBe($before);

    $response = actingAs($this->controller)->post('/api/accounting/imports/opening-balances', ['file' => csv($balanced), 'mode' => 'commit', 'opening_date' => '2026-07-01'], $this->headers + ['Accept' => 'application/json'])
        ->assertOk();

    asTenant($this->ctx['tenant_id'], function () use ($response): void {
        $journal = Journal::query()->findOrFail((string) $response->json('result.journal_id'));
        expect($journal->kind->value)->toBe('opening')
            ->and($journal->status->value)->toBe('pending_approval')
            ->and($journal->number)->toBeNull()
            ->and(DB::table('journal_lines')->where('journal_id', $journal->id)->count())->toBe(3);
    });
});

it('requires the import permissions', function (): void {
    actingAs($this->viewer)->post('/api/accounting/imports/chart-of-accounts', ['file' => csv(VALID_COA), 'mode' => 'dry_run'], $this->headers + ['Accept' => 'application/json'])->assertForbidden();
    actingAs($this->viewer)->post('/api/accounting/imports/opening-balances', ['file' => csv("account_code,debit,credit\n"), 'mode' => 'dry_run', 'opening_date' => '2026-07-01'], $this->headers + ['Accept' => 'application/json'])->assertForbidden();
});

it('shows the import page and renders a dry-run preview through it', function (): void {
    $this->withoutVite();

    actingAs($this->controller)->get('/accounting/imports', $this->headers)->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('accounting/Imports')->where('result', null));

    actingAs($this->controller)->post('/accounting/imports/chart-of-accounts', ['file' => csv(VALID_COA), 'mode' => 'dry_run'], $this->headers)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('accounting/Imports')
            ->where('result.type', 'chart-of-accounts')->where('result.valid', true)->where('result.preview.accounts_to_create', 3));
});
