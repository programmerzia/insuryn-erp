<?php

declare(strict_types=1);

namespace App\Modules\Distribution\Application\Licences;

use App\Modules\Platform\Messaging\Outbox;
use App\Modules\Platform\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Distribution design note §3 licence-expiry alerts at 60/30/7 days (erp.distribution.licence_alert_days). Every threshold a licence has
 * crossed is raised once, even when the job missed the day itself; a licence already followed by a valid licence of the same producer and a
 * covering class is not alerted. Each alert is queued as a `ProducerLicenceExpiring` outbox message; delivery is LATER, like dunning notices.
 */
final class LicenceExpiryAlerts
{
    public function __construct(private readonly Outbox $outbox) {}

    /** @return int alerts raised */
    public function run(CarbonImmutable $asOf): int
    {
        /** @var list<int> $thresholds */
        $thresholds = config('erp.distribution.licence_alert_days', [60, 30, 7]);
        rsort($thresholds);
        $horizon = $asOf->addDays($thresholds[0] ?? 0)->toDateString();
        $licences = DB::table('producer_licences')->where('status', 'active')->whereBetween('expires_on', [$asOf->toDateString(), $horizon])
            ->orderBy('expires_on')->get(['id', 'producer_id', 'class', 'expires_on', 'licence_no']);

        $raised = 0;
        foreach ($licences as $licence) {
            if ($this->alreadyRenewed($licence)) {
                continue;
            }
            $daysLeft = (int) $asOf->diffInDays(CarbonImmutable::parse((string) $licence->expires_on));
            foreach ($thresholds as $threshold) {
                if ($daysLeft > $threshold) {
                    continue;
                }
                $inserted = DB::table('producer_licence_alerts')->insertOrIgnore(['id' => (string) Str::uuid7(), 'tenant_id' => TenantContext::id(), 'licence_id' => $licence->id,
                    'days_before' => $threshold, 'raised_on' => $asOf->toDateString()]);
                if ($inserted === 1) {
                    $this->outbox->add('ProducerLicenceExpiring', ['licence_id' => (string) $licence->id, 'producer_id' => (string) $licence->producer_id,
                        'licence_no' => (string) $licence->licence_no, 'expires_on' => (string) $licence->expires_on, 'days_before' => $threshold]);
                    $raised++;
                }
            }
        }

        return $raised;
    }

    private function alreadyRenewed(\stdClass $licence): bool
    {
        $covering = $licence->class === 'both' ? ['both'] : [(string) $licence->class, 'both'];

        return DB::table('producer_licences')->where('producer_id', $licence->producer_id)->where('id', '<>', $licence->id)->where('status', 'active')
            ->whereIn('class', $covering)->where('expires_on', '>', $licence->expires_on)->where('issued_on', '<=', CarbonImmutable::parse((string) $licence->expires_on)->addDay()->toDateString())
            ->exists();
    }
}
