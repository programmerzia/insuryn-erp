<?php

declare(strict_types=1);

namespace App\Http\Pages;

use App\Modules\Platform\Preferences\UserPreferences;
use Illuminate\Support\Facades\DB;

/**
 * Flow audit defaults for new forms (UX brief §4 "sensible defaults"): the user's branch and small values remembered per user — the last channel a receipt
 * came by, the last product quoted — kept in the user's preferences under `drafts.<key>` as `{"value": "…"}`.
 */
final class FormDefaults
{
    public const LAST_RECEIPT_CHANNEL = 'last-receipt-channel';

    public const LAST_PRODUCT = 'last-product';

    public function __construct(private readonly UserPreferences $preferences) {}

    /**
     * The branch a new record starts in: the branch the user chose in the shell (preferences.branch_id), else the one branch the user's roles are scoped to,
     * else the entity's only active branch; null when none of these decides it.
     */
    public function branch(string $userId, string $entityId): ?string
    {
        $branches = DB::table('branches')->where('entity_id', $entityId)->where('status', 'active')->pluck('id')->map(fn (mixed $id): string => (string) $id)->all();
        $chosen = $this->preferences->of($userId)['branch_id'] ?? null;
        if (is_string($chosen) && in_array($chosen, $branches, true)) {
            return $chosen;
        }
        $scoped = array_values(array_intersect(array_unique(DB::table('user_roles')->where('user_id', $userId)->where('scope_type', 'branch')
            ->pluck('scope_id')->map(fn (mixed $id): string => (string) $id)->all()), $branches));
        if (count($scoped) === 1) {
            return $scoped[0];
        }

        return count($branches) === 1 ? $branches[0] : null;
    }

    public function remembered(string $userId, string $key): ?string
    {
        $drafts = $this->preferences->of($userId)['drafts'] ?? [];
        $value = is_array($drafts) && is_array($drafts[$key] ?? null) ? ($drafts[$key]['value'] ?? null) : null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function remember(string $userId, string $key, string $value): void
    {
        if ($this->remembered($userId, $key) !== $value) {
            $this->preferences->set($userId, "drafts.{$key}", ['value' => $value]);
        }
    }
}
