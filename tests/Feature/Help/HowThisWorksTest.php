<?php

declare(strict_types=1);

use App\Http\Help\HelpContent;
use App\Models\User;

use function Pest\Laravel\actingAs;

/**
 * Session S3: a "How this works" panel on every module (Policies, Receipts, Bank, Claims, Commission, Accounting, Close, Reports) — five to eight plain
 * sentences from market cross-check Part A on what the screen is for, what happens in the accounting and the next step — in English and Bangla.
 * The words live in resources/help/<module>.<en|bn>.md, not in code; the panel asks the server for them in the reader's language.
 */
beforeEach(function (): void {
    $this->withoutVite();
    $this->ctx = seedDemoTenant();
    $this->headers = ['X-Tenant' => $this->ctx['tenant_id']];
    $this->user = asTenant($this->ctx['tenant_id'], fn (): User => User::query()->findOrFail(userWithPermissions($this->ctx['tenant_id'], ['receipt.create'])));
});

it('has English and Bangla help for every module, each five to eight sentences with the three parts', function (): void {
    expect(HelpContent::MODULES)->toBe(['quotes', 'policies', 'renewals', 'receipts', 'bank', 'claims', 'commission', 'accounting', 'close', 'reports']);
    foreach (HelpContent::MODULES as $module) {
        foreach (['en', 'bn'] as $locale) {
            $path = resource_path("help/{$module}.{$locale}.md");
            expect(file_exists($path))->toBeTrue("{$module}.{$locale}.md is missing");
            $markdown = (string) file_get_contents($path);
            $body = implode(' ', array_filter(explode("\n", $markdown), fn (string $line): bool => ! str_starts_with($line, '#')));
            $sentences = preg_match_all('/[.?!।](\s|$)/u', $body);
            expect($sentences)->toBeGreaterThanOrEqual(5, "{$module}.{$locale} has {$sentences} sentences")->toBeLessThanOrEqual(8, "{$module}.{$locale} has {$sentences} sentences")
                ->and(substr_count($markdown, "\n## "))->toBe(3, "{$module}.{$locale} needs: what the screen is for, what happens in the accounting, the next step");
        }
    }
});

it('serves a module\'s help in the reader\'s language, as escaped HTML', function (): void {
    actingAs($this->user)->getJson('/help/receipts', $this->headers)->assertOk()
        ->assertJsonPath('module', 'receipts')->assertJsonPath('locale', 'en')->assertJsonPath('title', 'Receipts and suspense')
        ->assertJson(fn ($json) => $json->where('html', fn (string $html): bool => str_contains($html, '<h2>What happens in the accounting</h2>') && ! str_contains($html, '<h1>'))->etc());
    actingAs($this->user)->getJson('/help/receipts?locale=bn', $this->headers)->assertOk()->assertJsonPath('locale', 'bn')->assertJsonPath('title', 'রসিদ ও সাসপেন্স');

    // The chosen language is remembered for the user.
    actingAs($this->user)->putJson('/preferences/locale', ['value' => 'bn'], $this->headers)->assertNoContent();
    actingAs($this->user)->getJson('/help/claims', $this->headers)->assertJsonPath('locale', 'bn');
    actingAs($this->user)->putJson('/preferences/locale', ['value' => 'fr'], $this->headers)->assertUnprocessable();

    actingAs($this->user)->getJson('/help/payroll', $this->headers)->assertNotFound();
    $this->app['auth']->forgetGuards();
    Pest\Laravel\getJson('/help/claims', $this->headers)->assertUnauthorized();
});

it('never passes raw HTML from the help files through', function (): void {
    $html = app(HelpContent::class)->render("# Title\n\nText <script>alert(1)</script> and [a link](javascript:alert(1)).\n");
    expect(str_contains($html, '<script>'))->toBeFalse()
        ->and(str_contains($html, 'javascript:'))->toBeFalse()
        ->and($html)->toContain('&lt;script&gt;');
});

it('opens the panel on every module screen', function (): void {
    $pages = [
        'renewals' => ['renewals/Index'],
        'quotes' => ['quotations/Index', 'quotations/Workbench', 'proposals/Show', 'coverNotes/Index', 'underwriting/Referrals'],
        'policies' => ['policies/Index', 'policies/Show', 'policies/Create'],
        'receipts' => ['receipts/Index', 'receipts/Show', 'receipts/Create', 'receipts/Allocate', 'suspense/Index'],
        'bank' => ['bank/Index', 'bank/Show'],
        'claims' => ['claims/Index', 'claims/Show', 'claims/Create'],
        'commission' => ['commission/Index', 'commission/Statement', 'distribution/statements/Index'],
        'accounting' => ['accounting/journals/Index', 'accounting/journals/Show', 'accounting/TrialBalance'],
        'close' => ['close/Index', 'close/Run'],
        'reports' => ['reports/Index', 'reports/Show'],
    ];
    foreach ($pages as $module => $components) {
        foreach ($components as $component) {
            expect((string) file_get_contents(resource_path("js/pages/{$component}.vue")))->toContain("help=\"{$module}\"");
        }
    }
});
