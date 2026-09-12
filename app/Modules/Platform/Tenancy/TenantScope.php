<?php

declare(strict_types=1);

namespace App\Modules\Platform\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/** Second wall behind RLS: every Eloquent query is tenant-filtered. */
final class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (TenantContext::has()) {
            $builder->where($model->qualifyColumn('tenant_id'), TenantContext::id());
        } else {
            // No context → deliberately match nothing. Never silently query all tenants.
            $builder->whereRaw('1 = 0');
        }
    }
}
