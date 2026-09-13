<?php

declare(strict_types=1);

use App\Modules\Distribution\Domain\Compensation\Beneficiary;
use App\Modules\Distribution\Domain\Compensation\ClawbackSplit;
use App\Modules\Distribution\Domain\Compensation\CommissionLine;
use App\Modules\Distribution\Domain\Compensation\CompensationCalculator;
use App\Modules\Distribution\Domain\Compensation\ComplianceIssue;
use App\Modules\Distribution\Domain\Compensation\EarnedShare;
use App\Modules\Distribution\Domain\Compensation\RuleTerms;
use App\Modules\Distribution\Domain\Compensation\SchemeTerms;
use App\Modules\Distribution\Domain\Compensation\Trigger;
use App\Modules\Distribution\Domain\ComplianceProfile;

/**
 * Distribution design note §2 (slice D5) golden fixtures for the compensation calculation, like the posting-rule fixtures of design §9.1:
 * each file in tests/Fixtures/compensation states a scheme, a hierarchy snapshot, rules and a trigger (or earned shares to claw back) and the
 * exact result. Change the calculation → update the fixture, never the other way.
 */
$fixtures = glob(__DIR__.'/../../Fixtures/compensation/*.json') ?: [];

it('calculates golden fixture :dataset', function (string $file): void {
    /** @var array<string, mixed> $fx */
    $fx = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);

    if (isset($fx['clawback'])) {
        $shares = array_values(array_map(fn (array $e): EarnedShare => new EarnedShare($e[0], $e[1], $e[2], $e[3]), $fx['clawback']['entries']));
        $clawbacks = array_map(fn (EarnedShare $c): array => [$c->beneficiaryId, $c->baseMinor, $c->amountMinor, $c->withholdingMinor],
            ClawbackSplit::split($shares, $fx['clawback']['unearned_remaining'], $fx['clawback']['net_premium']));
        expect($clawbacks)->toBe($fx['expected']['clawbacks']);

        return;
    }

    $scheme = new SchemeTerms($fx['scheme']['mode'], ComplianceProfile::fromArray($fx['scheme']['compliance_profile']), $fx['scheme']['withholding_bp']);
    $chain = [];
    foreach (array_values($fx['producers']) as $depth => $p) {
        $chain[] = new Beneficiary($p['key'], $p['key'], $p['type'], $p['status'], $p['level'], $depth, $p['licensed']);
    }
    $rules = array_values(array_map(fn (array $r): RuleTerms => new RuleTerms($r['key'], $r['product'] ?? null, $r['producer_type'] ?? null, $r['level_code'] ?? null, $r['basis'],
        $r['policy_year_from'], $r['policy_year_to'], $r['rate_bp'] ?? 0, $r['override_rate_bp'] ?? 0, $r['cap_bp'] ?? null, $r['min_persistency_bp'] ?? null,
        $r['renewal_requires_valid_licence'] ?? true, $r['pays_after_termination'] ?? false), $fx['rules']));
    $trigger = new Trigger($fx['trigger']['basis'], $fx['trigger']['base'], $fx['trigger']['policy_year'], $fx['trigger']['product'], $fx['trigger']['product_class']);

    $result = (new CompensationCalculator())->calculate($scheme, $rules, $chain, $trigger);

    expect(array_map(fn (CommissionLine $l): array => [$l->beneficiary->producerId, $l->role, $l->levelCode, $l->ruleId, $l->rateBp, $l->amountMinor, $l->withholdingMinor, $l->conditional], $result->lines))
        ->toBe($fx['expected']['lines'])
        ->and(array_map(fn (ComplianceIssue $i): array => [$i->producerId, $i->reasonCode], $result->issues))->toBe($fx['expected']['issues']);
})->with(array_combine(array_map('basename', $fixtures), $fixtures));
