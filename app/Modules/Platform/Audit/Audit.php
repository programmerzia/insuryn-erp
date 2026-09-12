<?php

declare(strict_types=1);

namespace App\Modules\Platform\Audit;

use App\Modules\Platform\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Business audit trail (design §2.1 audit_events, append-only; spec §7 "business audit is what auditors
 * read"). Rows are written in the caller's transaction, so an audited change and its audit row commit
 * together. `permission` names the permission exercised: segregation-of-duties checks read it (§7.3).
 */
final class Audit
{
    private const REQUEST_ID_ATTRIBUTE = 'audit.request_id';

    /**
     * @param array<string, mixed>|null $before state before the action (null when the object is new)
     * @param array<string, mixed>|null $after state after the action
     * @param Actor|null $actor defaults to the authenticated user, otherwise the system
     */
    public function record(
        string $action,
        AuditSubject $subject,
        ?array $before,
        ?array $after,
        ?string $reason = null,
        ?string $permission = null,
        ?Actor $actor = null,
    ): string {
        $actor ??= $this->currentActor();
        $id = (string) Str::uuid7();

        DB::table('audit_events')->insert([
            'id' => $id, 'tenant_id' => TenantContext::id(), 'occurred_at' => CarbonImmutable::now(),
            'actor_user_id' => $actor->userId, 'actor_type' => $actor->type,
            'action' => $action, 'permission' => $permission,
            'object_type' => $subject->type, 'object_id' => $subject->id,
            'before' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
            'after' => $after === null ? null : json_encode($after, JSON_THROW_ON_ERROR),
            'reason' => $reason,
        ] + $this->requestDetails());

        return $id;
    }

    private function currentActor(): Actor
    {
        $userId = Auth::hasUser() ? Auth::id() : null;

        return is_string($userId) ? Actor::user($userId) : Actor::system();
    }

    /** @return array{request_id: string|null, ip: string|null, user_agent: string|null} */
    private function requestDetails(): array
    {
        $request = app()->bound('request') ? app('request') : null;
        if (! $request instanceof Request || $request->route() === null) {
            return ['request_id' => null, 'ip' => null, 'user_agent' => null];
        }
        if (! is_string($request->attributes->get(self::REQUEST_ID_ATTRIBUTE))) {
            $header = $request->header('X-Request-Id');
            $request->attributes->set(self::REQUEST_ID_ATTRIBUTE, is_string($header) && Str::isUuid($header) ? $header : (string) Str::uuid7());
        }
        $userAgent = $request->userAgent();

        return [
            'request_id' => (string) $request->attributes->get(self::REQUEST_ID_ATTRIBUTE),
            'ip' => $request->ip(),
            'user_agent' => $userAgent === null ? null : mb_substr($userAgent, 0, 255),
        ];
    }
}
