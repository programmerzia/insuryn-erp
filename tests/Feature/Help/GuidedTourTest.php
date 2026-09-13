<?php

declare(strict_types=1);

use App\Models\User;

use function Pest\Laravel\actingAs;

/**
 * Session S4: an optional guided tour of the Part A flow, starting from Home — issue a policy → receive → allocate suspense → import the bank
 * statement → register, reserve, approve and pay a claim → run the close. The words are in resources/help/tour.<en|bn>.md; where the user is in
 * the tour (active, dismissed or finished, and the step) is a per-user preference, so it can be dismissed and resumed on any device.
 */
beforeEach(function (): void {
    $this->withoutVite();
    $this->ctx = seedDemoTenant();
    $this->headers = ['X-Tenant' => $this->ctx['tenant_id']];
    $this->user = asTenant($this->ctx['tenant_id'], fn (): User => User::query()->findOrFail(userWithPermissions($this->ctx['tenant_id'], ['receipt.create'])));
});

it('serves the tour steps of the Part A flow in order, in English and Bangla', function (): void {
    $ids = ['home', 'issue-policy', 'receive', 'suspense', 'bank', 'register-claim', 'settle-claim', 'close'];
    $english = actingAs($this->user)->getJson('/help/tour', $this->headers)->assertOk()->assertJsonPath('locale', 'en')->json('steps');
    $bangla = actingAs($this->user)->getJson('/help/tour?locale=bn', $this->headers)->assertOk()->json('steps');

    expect(array_column($english, 'id'))->toBe($ids)
        ->and(array_column($bangla, 'id'))->toBe($ids)
        ->and($english[1]['title'])->toBe('Issue a policy')
        ->and($english[0]['html'])->toContain('<p>');
    foreach ([...$english, ...$bangla] as $step) {
        expect($step['title'])->not->toBe('')->and(strip_tags($step['html']))->not->toBe('');
    }
});

it('remembers where the user is in the tour', function (): void {
    actingAs($this->user)->putJson('/preferences/tour', ['value' => ['status' => 'active', 'step' => 3]], $this->headers)->assertNoContent();
    actingAs($this->user)->get('/home', $this->headers)->assertInertia(fn ($page) => $page->where('preferences.tour', ['status' => 'active', 'step' => 3]));
    actingAs($this->user)->putJson('/preferences/tour', ['value' => ['status' => 'dismissed', 'step' => 3]], $this->headers)->assertNoContent();
    actingAs($this->user)->putJson('/preferences/tour', ['value' => ['status' => 'paused', 'step' => 3]], $this->headers)->assertUnprocessable();
    actingAs($this->user)->putJson('/preferences/tour', ['value' => ['status' => 'active', 'step' => 99]], $this->headers)->assertUnprocessable();
});
