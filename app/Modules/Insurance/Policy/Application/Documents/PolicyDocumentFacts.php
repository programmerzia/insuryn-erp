<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Policy\Application\Documents;

use App\Modules\Platform\Authorization\AuthorizationScope;
use App\Modules\Platform\Documents\Generation\DocumentSubject;
use App\Modules\Platform\Documents\Generation\DocumentValues;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use Illuminate\Support\Facades\DB;

/** What every policy document prints about its policy (slice R8): the insurer, the parties, the product and class, the period. Read-only. */
final class PolicyDocumentFacts
{
    /** @throws BusinessRuleViolation DOCUMENT_OBJECT_UNKNOWN */
    public function policy(string $policyId): \stdClass
    {
        $policy = DB::table('policies as p')->join('product_versions as v', 'v.id', '=', 'p.product_version_id')->join('products as pr', 'pr.id', '=', 'p.product_id')
            ->where('p.id', $policyId)->select(['p.*', 'v.class_code', 'pr.code as product_code', 'pr.name as product_name'])->first();
        if (! $policy instanceof \stdClass) {
            throw new BusinessRuleViolation('DOCUMENT_OBJECT_UNKNOWN', 'That policy does not exist.');
        }

        return $policy;
    }

    public function subject(\stdClass $policy, ?string $number = null): DocumentSubject
    {
        return new DocumentSubject('policy', (string) $policy->id, $number ?? ($policy->number === null ? null : (string) $policy->number),
            $policy->class_code === null ? null : (string) $policy->class_code, AuthorizationScope::branch((string) $policy->entity_id, (string) $policy->branch_id));
    }

    /**
     * company, parties, product, period and currency for the variable bag.
     *
     * @return array<string, mixed>
     */
    public function common(\stdClass $policy, string $locale): array
    {
        $l = fn (string $en, string $bn): string => DocumentValues::label($locale, $en, $bn);
        $parties = [['role' => $l('Policyholder', 'পলিসিগ্রহীতা'), 'name' => (string) DB::table('parties')->where('id', $policy->policyholder_party_id)->value('display_name')]];
        $payers = DB::table('policy_payers as pp')->join('parties as pa', 'pa.id', '=', 'pp.party_id')->where('pp.policy_id', $policy->id)->where('pp.party_id', '<>', $policy->policyholder_party_id)
            ->orderBy('pa.display_name')->pluck('pa.display_name');
        foreach ($payers as $payer) {
            $parties[] = ['role' => $l('Payer', 'প্রিমিয়াম প্রদানকারী'), 'name' => (string) $payer];
        }
        if ($policy->agent_id !== null) {
            $agent = DB::table('producers as a')->join('parties as pa', 'pa.id', '=', 'a.party_id')->where('a.id', $policy->agent_id)->first(['a.code', 'pa.display_name']);
            if ($agent instanceof \stdClass) {
                $parties[] = ['role' => $l('Agent', 'এজেন্ট'), 'name' => "{$agent->display_name} ({$agent->code})"];
            }
        }
        $class = $policy->class_code === null ? null : DB::table('product_classes')->where('code', $policy->class_code)->first(['name_en', 'name_bn']);

        return [
            'company' => ['name' => (string) DB::table('legal_entities')->where('id', $policy->entity_id)->value('name')],
            'currency' => (string) $policy->currency,
            'parties' => $parties,
            'product' => ['code' => (string) $policy->product_code, 'name' => (string) $policy->product_name,
                'class' => $class instanceof \stdClass ? $l((string) $class->name_en, (string) $class->name_bn) : ''],
            'period' => ['from' => DocumentValues::date((string) $policy->inception), 'to' => DocumentValues::date((string) $policy->expiry)],
        ];
    }

    /**
     * The frozen rating result of the policy, when rating (R7) stored one; null before that.
     *
     * @return array<string, mixed>|null
     */
    public function ratingResult(\stdClass $policy): ?array
    {
        if (! property_exists($policy, 'rating_result') || ! is_string($policy->rating_result) || $policy->rating_result === '') {
            return null;
        }
        $decoded = json_decode($policy->rating_result, true);

        return is_array($decoded) ? $decoded : null;
    }
}
