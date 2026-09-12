<?php

declare(strict_types=1);

namespace App\Modules\Platform\Tenancy;

use Illuminate\Database\Eloquent\Model;

/** @mixin Model */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope());
        static::creating(function (Model $model): void {
            if ($model->getAttribute('tenant_id') === null) {
                $model->setAttribute('tenant_id', TenantContext::id());
            }
        });
    }
}
