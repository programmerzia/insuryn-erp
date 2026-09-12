<?php

declare(strict_types=1);

namespace App\Modules\Platform\Authorization;

use RuntimeException;

final class PermissionDenied extends RuntimeException
{
    public function __construct(public readonly string $userId, public readonly string $permission, string $message = '')
    {
        parent::__construct($message !== '' ? $message : "User {$userId} lacks permission {$permission}.");
    }
}
