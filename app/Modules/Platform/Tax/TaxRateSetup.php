<?php

declare(strict_types=1);

namespace App\Modules\Platform\Tax;

use App\Modules\Platform\Audit\Actor;
use App\Modules\Platform\Audit\Audit;
use App\Modules\Platform\Audit\AuditSubject;
use App\Modules\Platform\Authorization\PermissionChecker;
use App\Modules\Platform\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Setup wizard step 4 (session S1): the premium tax rate a first product needs. A rate already in force on the date is kept — rates are
 * effective-dated configuration and are never overwritten here. Held by whoever configures products (product.manage).
 */
final class TaxRateSetup
{
    public function __construct(
        private readonly PermissionChecker $permissions,
        private readonly Audit $audit,
    ) {}

    /** @return bool whether a rate was created */
    public function ensure(string $jurisdiction, string $taxType, int $rateBasisPoints, bool $inclusive, CarbonImmutable $from, string $actorUserId): bool
    {
        $this->permissions->authorize($actorUserId, 'product.manage');
        $day = $from->toDateString();
        $inForce = DB::table('tax_rates')->where('jurisdiction', $jurisdiction)->where('tax_type', $taxType)->where('withholding', false)
            ->where('effective_from', '<=', $day)->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $day))->exists();
        if ($inForce) {
            return false;
        }
        $id = (string) Str::uuid7();
        DB::table('tax_rates')->insert(['id' => $id, 'tenant_id' => TenantContext::id(), 'jurisdiction' => $jurisdiction, 'tax_type' => $taxType, 'rate_bp' => $rateBasisPoints,
            'inclusive' => $inclusive, 'withholding' => false, 'effective_from' => $day]);
        $this->audit->record('tax_rate.created', AuditSubject::of('tax_rate', $id), null,
            ['jurisdiction' => $jurisdiction, 'tax_type' => $taxType, 'rate_bp' => $rateBasisPoints, 'effective_from' => $day], null, 'product.manage', Actor::user($actorUserId));

        return true;
    }
}
