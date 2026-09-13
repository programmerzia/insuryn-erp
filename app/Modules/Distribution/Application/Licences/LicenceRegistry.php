<?php

declare(strict_types=1);

namespace App\Modules\Distribution\Application\Licences;

use App\Modules\Distribution\Application\ProducerDirectory;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Distribution design note §3 "no new business for a producer without a valid licence of the right class (blocking)". A licence is valid on a
 * day when it is active and the day falls between its issue and expiry dates inclusive; class `both` covers life and non-life.
 * ASSUMPTION A-14: every producer type needs a licence (erp.distribution.licence_required_types), and a suspended or terminated producer
 * writes no new business. Renewals are not new business (the policy module does not ask).
 */
final class LicenceRegistry
{
    public function __construct(private readonly ProducerDirectory $producers) {}

    /** @param string $insuranceClass life | non_life */
    public function validLicenceId(string $producerId, string $insuranceClass, CarbonImmutable $on): ?string
    {
        $id = DB::table('producer_licences')->where('producer_id', $producerId)->where('status', 'active')
            ->whereIn('class', [$insuranceClass, 'both'])->where('issued_on', '<=', $on->toDateString())->where('expires_on', '>=', $on->toDateString())
            ->orderByDesc('expires_on')->value('id');

        return $id === null ? null : (string) $id;
    }

    /** @throws BusinessRuleViolation PRODUCER_NOT_ACTIVE, LICENCE_REQUIRED */
    public function assertMayWriteNewBusiness(string $producerId, string $insuranceClass, CarbonImmutable $on): void
    {
        $producer = $this->producers->get($producerId);
        if (! $producer->isActive()) {
            throw new BusinessRuleViolation('PRODUCER_NOT_ACTIVE', "{$producer->code} is {$producer->status}, so it cannot write new business.");
        }
        /** @var list<string> $licensed */
        $licensed = config('erp.distribution.licence_required_types', ['agent', 'agency_org', 'bdo', 'broker', 'partner']);
        if (in_array($producer->type, $licensed, true) && $this->validLicenceId($producerId, $insuranceClass, $on) === null) {
            $class = str_replace('_', '-', $insuranceClass);
            throw new BusinessRuleViolation('LICENCE_REQUIRED', "{$producer->code} has no valid {$class} licence on {$on->toDateString()}, so it cannot write new business.");
        }
    }
}
