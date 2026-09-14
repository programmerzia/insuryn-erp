<?php

declare(strict_types=1);

namespace App\Modules\Platform\Preferences;

use App\Modules\Platform\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Per-user interface state (UX brief §3, §4): theme, density, sidebar, inspector widths, pinned tabs, table layouts and saved views,
 * command-palette recents, form drafts, the help panel's language and state, and the guided tour's progress. A preference is a key — `theme`, or `<group>.<id>` such as `splits.receipts` — and a validated
 * value; anything unknown is refused so the document never collects junk. Stored as one JSON document per user (tenant + RLS).
 */
final class UserPreferences
{
    public const DEFAULTS = ['theme' => 'system', 'density' => 'compact', 'sidebar_collapsed' => false, 'branch_id' => null,
        'splits' => [], 'tabs' => [], 'tables' => [], 'views' => [], 'recents' => [], 'drafts' => [], 'locale' => 'en', 'help_open' => false, 'help_locale' => null, 'tour' => null];

    private const GROUPS = ['splits', 'tables', 'views', 'drafts'];

    /** @return array<string, mixed> */
    public function of(string $userId): array
    {
        $stored = DB::table('user_preferences')->where('user_id', $userId)->value('preferences');
        $values = is_string($stored) ? json_decode($stored, true) : [];

        return array_replace(self::DEFAULTS, is_array($values) ? $values : []);
    }

    public function set(string $userId, string $key, mixed $value): void
    {
        $parts = explode('.', $key, 2);
        $name = $parts[0];
        $id = $parts[1] ?? null;
        $rules = $this->rules($name, $id);
        Validator::make(['value' => $value], $rules, ['value.max' => 'That preference is too large.'])->validate();

        DB::transaction(function () use ($userId, $name, $id, $value): void {
            $row = DB::table('user_preferences')->where('user_id', $userId)->lockForUpdate()->first(['id', 'preferences']);
            $current = $row === null ? [] : (array) json_decode((string) $row->preferences, true);
            if ($id === null) {
                $current[$name] = $value;
            } else {
                $group = is_array($current[$name] ?? null) ? $current[$name] : [];
                $group[$id] = $value;
                $current[$name] = $group;
            }
            $json = json_encode($current, JSON_THROW_ON_ERROR);
            if ($row === null) {
                DB::table('user_preferences')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => TenantContext::id(), 'user_id' => $userId,
                    'preferences' => $json, 'created_at' => now(), 'updated_at' => now()]);
            } else {
                DB::table('user_preferences')->where('id', $row->id)->update(['preferences' => $json, 'updated_at' => now()]);
            }
        });
    }

    /** @return array<string, mixed> */
    private function rules(string $name, ?string $id): array
    {
        $grouped = in_array($name, self::GROUPS, true);
        if ($grouped !== ($id !== null) || ($id !== null && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id) !== 1)) {
            throw ValidationException::withMessages(['key' => 'Unknown preference.']);
        }

        return match ($name) {
            'theme' => ['value' => ['required', 'in:system,light,dark']],
            'density' => ['value' => ['required', 'in:compact,comfortable']],
            'sidebar_collapsed' => ['value' => ['required', 'boolean:strict']],
            // Session S3/S4: the user's language (GA-30: chosen in the user menu) and whether the help panel is open; the guided tour's state (null = never started).
            'locale' => ['value' => ['required', 'in:en,bn']],
            'help_open' => ['value' => ['required', 'boolean:strict']],
            // GA-30: the help panel's own language switch reads the panel in the other language without changing the user's language (null = the user's language).
            'help_locale' => ['value' => ['present', 'nullable', 'in:en,bn']],
            'tour' => ['value' => ['present', 'nullable', 'array'], 'value.status' => ['required_with:value', 'in:active,dismissed,finished'], 'value.step' => ['required_with:value', 'integer', 'between:0,50']],
            'branch_id' => ['value' => ['nullable', 'uuid']],
            'splits' => ['value' => ['required', 'integer', 'between:240,1400']],
            'tabs' => ['value' => ['present', 'array', 'list', 'max:8'], 'value.*.href' => ['required', 'string', 'max:255', 'regex:#^/(?!/)#'], 'value.*.title' => ['required', 'string', 'max:80']],
            'recents' => ['value' => ['present', 'array', 'list', 'max:20'], 'value.*.href' => ['required', 'string', 'max:255', 'regex:#^/(?!/)#'],
                'value.*.label' => ['required', 'string', 'max:120'], 'value.*.kind' => ['required', 'string', 'max:32']],
            'tables' => ['value' => ['present', 'array'], 'value.columns' => ['sometimes', 'array', 'max:40'], 'value.hidden' => ['sometimes', 'array', 'max:40'],
                'value.widths' => ['sometimes', 'array', 'max:40'], 'value.widths.*' => ['integer', 'between:40,800'], 'value.sort' => ['sometimes', 'array', 'max:5']],
            'views' => ['value' => ['present', 'array', 'list', 'max:20'], 'value.*.name' => ['required', 'string', 'max:60'], 'value.*.query' => ['present', 'string', 'max:1000'],
                'value.*.columns' => ['sometimes', 'array', 'max:40']],
            'drafts' => ['value' => ['nullable', 'array', 'max:200']],
            default => throw ValidationException::withMessages(['key' => 'Unknown preference.']),
        };
    }
}
