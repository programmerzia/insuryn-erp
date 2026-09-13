<?php

declare(strict_types=1);

use App\Http\Help\HelpContent;
use App\Models\User;
use App\Modules\Insurance\Policy\Application\PolicyLifecycle;
use App\Modules\Insurance\Policy\Application\QuoteRequest;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountRolesSeeder;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;

/**
 * Session S5: every "View accounting" panel (and the confirmation before money moves) captions each journal line in one plain sentence — "Customer
 * owes us the premium", "Cover not yet provided — a liability until time passes" — chosen by the line's account role and side from
 * resources/help/roles.<en|bn>.md. The screens therefore receive each line's account role.
 */
beforeEach(function (): void {
    $this->withoutVite();
    $this->ctx = seedDemoTenant();
    $this->world = seedInsuranceWorld($this->ctx, 'monthly');
    $this->headers = ['X-Tenant' => $this->ctx['tenant_id']];
    $this->admin = asTenant($this->ctx['tenant_id'], fn (): User => User::query()->findOrFail((string) $this->world['admin']));
    $this->policyId = asTenant($this->ctx['tenant_id'], fn (): string => app(PolicyLifecycle::class)->quote(new QuoteRequest($this->ctx['entity_id'], $this->ctx['branch_id'],
        $this->world['product_id'], $this->world['policyholder_id'], null, CarbonImmutable::parse('2026-09-01'), 12_000_000, 'BDT'), $this->world['admin'])->id);
});

it('has a debit and a credit caption for every account role, in English and Bangla', function (): void {
    foreach (HelpContent::LOCALES as $locale) {
        $captions = app(HelpContent::class)->roleCaptions($locale);
        expect(array_keys($captions))->toEqualCanonicalizing(array_keys(AccountRolesSeeder::ROLES));
        foreach ($captions as $role => $caption) {
            expect($caption['debit'])->not->toBe('')->and($caption['credit'])->not->toBe('')
                ->and(mb_strlen($caption['debit']))->toBeLessThanOrEqual(80)->and(mb_strlen($caption['credit']))->toBeLessThanOrEqual(80);
        }
    }
    expect(app(HelpContent::class)->roleCaptions('en')['premium_receivable']['debit'])->toBe('Customer owes us the premium')
        ->and(app(HelpContent::class)->roleCaptions('en')['unearned_premium']['credit'])->toBe('Cover not yet provided — a liability until time passes');
});

it('serves the captions in the reader\'s language', function (): void {
    actingAs($this->admin)->getJson('/help/roles', $this->headers)->assertOk()->assertJsonPath('locale', 'en')
        ->assertJsonPath('captions.premium_tax_payable.credit', 'VAT we collect for the government');
    actingAs($this->admin)->getJson('/help/roles?locale=bn', $this->headers)->assertOk()->assertJsonPath('captions.bank_main.debit', 'ব্যাংকে টাকা এসেছে');
});

it('sends each journal line\'s account role to the accounting panel and the posting preview', function (): void {
    actingAs($this->admin)->postJson("/policies/{$this->policyId}/issue", ['on' => '2026-09-01'], [...$this->headers, 'X-Journal-Preview' => '1', 'Accept' => 'application/json'])
        ->assertOk()->assertJsonPath('journals.0.lines.*.role', ['premium_receivable', 'unearned_premium', 'premium_tax_payable']);

    asTenant($this->ctx['tenant_id'], fn () => app(PolicyLifecycle::class)->issue($this->policyId, CarbonImmutable::parse('2026-09-01'), $this->world['admin']));
    actingAs($this->admin)->get("/policies/{$this->policyId}", $this->headers)->assertInertia(fn (AssertableInertia $page) => $page
        ->loadDeferredProps('history', fn (AssertableInertia $reload) => $reload->where('accounting.0.lines', fn ($lines): bool => array_column((array) json_decode((string) json_encode($lines), true), 'role')
            === ['premium_receivable', 'unearned_premium', 'premium_tax_payable'])));
});
