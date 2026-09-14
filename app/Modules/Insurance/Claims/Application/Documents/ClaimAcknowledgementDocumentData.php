<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Claims\Application\Documents;

use App\Modules\Platform\Documents\Generation\DocumentDataProvider;
use App\Modules\Platform\Documents\Generation\DocumentSubject;
use App\Modules\Platform\Documents\Generation\DocumentValues;
use App\Modules\Platform\Documents\Templates\DocumentTemplateCode;

/**
 * Gap audit GA-41: the claim acknowledgement for the claimant — the claim number to quote, the policy, the loss as reported and the date it was
 * received. It prints no amount: the reserve is the insurer's estimate, not an offer. Any registered claim can be acknowledged, including a
 * rejected or closed one (a copy for the file). ASSUMPTION: A-183.
 */
final class ClaimAcknowledgementDocumentData implements DocumentDataProvider
{
    public function __construct(private readonly ClaimDocumentFacts $facts) {}

    public function objectType(): string
    {
        return 'claim';
    }

    public function templateCodes(): array
    {
        return [DocumentTemplateCode::ClaimAck];
    }

    public function subject(string $objectId, DocumentTemplateCode $code): DocumentSubject
    {
        $claim = $this->facts->claim($objectId);

        return $this->facts->subject($claim, $this->facts->policy($claim), (string) $claim->number);
    }

    public function variables(string $objectId, DocumentTemplateCode $code, string $locale): array
    {
        $claim = $this->facts->claim($objectId);
        $policy = $this->facts->policy($claim);
        $bag = $this->facts->variables($claim, $policy, $locale);

        return [
            ...$bag['common'],
            'document' => ['title' => $code->title($locale), 'number' => (string) $claim->number, 'date' => DocumentValues::date((string) $claim->reported_on)],
            'details' => $bag['details'],
        ];
    }
}
