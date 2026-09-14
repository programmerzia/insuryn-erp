<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Posting;

use App\Modules\Accounting\Exceptions\PostingFailedException;
use Illuminate\Support\Facades\DB;

/**
 * Design §2.1 dimension_requirements (gap audit GA-47, D-74): dimensions a tenant requires on an event type on top of its posting rule's
 * `dimensions.required`. The posting engine checks them before building the journal, with the rule's own failure (DIMENSION_MISSING).
 * ASSUMPTION: A-187 — the defaults below: branch, product and class (lob) on the policy, premium earning and claim events.
 */
final class TenantDimensionRequirements
{
    public const DEFAULTS = [
        'POLICY_ISSUED' => ['branch', 'product', 'lob'], 'POLICY_ENDORSED' => ['branch', 'product', 'lob'], 'POLICY_CANCELLED' => ['branch', 'product', 'lob'],
        'PREMIUM_EARNED' => ['branch', 'product', 'lob'],
        'CLAIM_RESERVED' => ['branch', 'product', 'lob'], 'CLAIM_RESERVE_ADJUSTED' => ['branch', 'product', 'lob'], 'CLAIM_APPROVED' => ['branch', 'product', 'lob'],
        'CLAIM_PAID' => ['branch', 'product', 'lob'], 'CLAIM_RECOVERED' => ['branch', 'product', 'lob'], 'CLAIM_CLOSED' => ['branch', 'product', 'lob'],
    ];

    /**
     * @param array<string, mixed> $dimensions the event's dimensions
     *
     * @throws PostingFailedException DIMENSION_MISSING
     */
    public function assertPresent(string $eventType, array $dimensions): void
    {
        $required = DB::table('dimension_requirements')->where('event_type', $eventType)->where('required', true)->orderBy('dimension_code')->pluck('dimension_code');
        foreach ($required as $code) {
            if (! isset($dimensions[(string) $code]) || $dimensions[(string) $code] === '') {
                throw new PostingFailedException('DIMENSION_MISSING', "Required dimension '{$code}' missing (tenant requirement for {$eventType})");
            }
        }
    }

    /** Writes the default requirements for the tenant in context; existing rows (and a tenant's own changes to them) are kept. Returns the rows added. */
    public static function seedCurrentTenant(string $tenantId): int
    {
        $added = 0;
        foreach (self::DEFAULTS as $eventType => $codes) {
            foreach ($codes as $code) {
                $added += DB::table('dimension_requirements')->insertOrIgnore(['tenant_id' => $tenantId, 'event_type' => $eventType, 'dimension_code' => $code, 'required' => true]);
            }
        }

        return $added;
    }
}
