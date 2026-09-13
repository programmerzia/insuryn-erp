<?php

declare(strict_types=1);

namespace App\Modules\Distribution\Application\Portal;

use App\Modules\Distribution\Application\ProducerDirectory;
use App\Modules\Distribution\Application\ProducerSummary;
use App\Modules\Platform\Administration\PortalAccounts;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use Illuminate\Support\Facades\DB;

/**
 * Producer portal access (design note §5). ASSUMPTION A-26: a producer has at most one portal user, whose portal role may record receipts and
 * allocate them within the producer's branch (collection recording) and nothing else; reads are limited to the producer's own business by the
 * portal endpoints themselves. Only active producers use the portal.
 */
final class ProducerPortalAccess
{
    public const ROLE = 'producer_portal';
    public const PERMISSIONS = ['receipt.create', 'receipt.allocate'];

    public function __construct(
        private readonly ProducerDirectory $producers,
        private readonly PortalAccounts $accounts,
    ) {}

    /** @return string the portal user id */
    public function grant(string $producerId, string $email, string $actorUserId): string
    {
        $producer = $this->producers->get($producerId);
        if (DB::table('producers')->where('id', $producerId)->whereNotNull('portal_user_id')->exists()) {
            throw new BusinessRuleViolation('PORTAL_ALREADY_GRANTED', "{$producer->code} already has portal access.");
        }

        return DB::transaction(function () use ($producer, $email, $actorUserId): string {
            $name = (string) DB::table('parties')->where('id', $producer->partyId)->value('display_name');
            $userId = $this->accounts->create($name, $email, self::ROLE, 'Producer portal', self::PERMISSIONS, 'branch', $producer->branchId, 'agent.manage', $actorUserId);
            DB::table('producers')->where('id', $producer->id)->update(['portal_user_id' => $userId, 'updated_at' => now()]);

            return $userId;
        });
    }

    /** The producer a portal user acts for, if any. */
    public function producerFor(string $userId): ?ProducerSummary
    {
        $id = DB::table('producers')->where('portal_user_id', $userId)->value('id');

        return $id === null ? null : $this->producers->find((string) $id);
    }
}
