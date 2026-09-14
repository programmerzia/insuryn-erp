<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Claims\Application\Documents;

use App\Modules\Insurance\Policy\Application\Documents\PolicyDocumentFacts;
use App\Modules\Platform\Authorization\AuthorizationScope;
use App\Modules\Platform\Documents\Generation\DocumentSubject;
use App\Modules\Platform\Documents\Generation\DocumentValues;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use Illuminate\Support\Facades\DB;

/** Gap audit GA-41: what every claim document prints about its claim and policy — the insurer, the policyholder, the product, the period, the loss. */
final class ClaimDocumentFacts
{
    public function __construct(private readonly PolicyDocumentFacts $policies) {}

    /** @throws BusinessRuleViolation DOCUMENT_OBJECT_UNKNOWN */
    public function claim(string $claimId): \stdClass
    {
        $claim = DB::table('claims')->where('id', $claimId)->first();
        if (! $claim instanceof \stdClass) {
            throw new BusinessRuleViolation('DOCUMENT_OBJECT_UNKNOWN', 'That claim does not exist.');
        }

        return $claim;
    }

    public function policy(\stdClass $claim): \stdClass
    {
        return $this->policies->policy((string) $claim->policy_id);
    }

    /** The PDF belongs on the claim page, in the claim's branch; the policy's class chooses a class template. */
    public function subject(\stdClass $claim, \stdClass $policy, string $number): DocumentSubject
    {
        return new DocumentSubject('claim', (string) $claim->id, $number, $policy->class_code === null ? null : (string) $policy->class_code,
            AuthorizationScope::branch((string) $claim->entity_id, (string) $claim->branch_id));
    }

    /**
     * company, currency, parties, product and period, and the claim's details rows.
     *
     * @return array{common: array<string, mixed>, details: list<array{label: string, value: string}>}
     */
    public function variables(\stdClass $claim, \stdClass $policy, string $locale): array
    {
        $l = fn (string $en, string $bn): string => DocumentValues::label($locale, $en, $bn);

        return [
            'common' => $this->policies->common($policy, $locale),
            'details' => [
                ['label' => $l('Claim', 'দাবি নম্বর'), 'value' => (string) $claim->number],
                ['label' => $l('Policy', 'পলিসি'), 'value' => (string) $policy->number],
                ['label' => $l('Date of loss', 'ক্ষতির তারিখ'), 'value' => DocumentValues::date((string) $claim->loss_date)],
                ['label' => $l('Reported on', 'দাবি জানানোর তারিখ'), 'value' => DocumentValues::date((string) $claim->reported_on)],
                ['label' => $l('What happened', 'ঘটনার বিবরণ'), 'value' => (string) $claim->description],
            ],
        ];
    }
}
