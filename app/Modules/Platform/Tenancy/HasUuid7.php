<?php

declare(strict_types=1);

namespace App\Modules\Platform\Tenancy;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** @mixin Model */
trait HasUuid7
{
    public static function bootHasUuid7(): void
    {
        static::creating(function (Model $model): void {
            if ($model->getKey() === null) {
                $model->setAttribute($model->getKeyName(), (string) Str::uuid7());
            }
        });
    }

    public function getIncrementing(): bool
    {
        return false;
    }

    public function getKeyType(): string
    {
        return 'string';
    }
}
