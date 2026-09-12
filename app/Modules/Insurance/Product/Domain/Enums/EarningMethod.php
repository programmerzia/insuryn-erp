<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Product\Domain\Enums;

/**
 * Design §2.4 product_versions.earning_method. `24ths` exists in the design but is not implemented by the
 * earning batch yet, so new versions cannot use it (ASSUMPTION A-4, OPEN #4).
 */
enum EarningMethod: string
{
    case Daily365 = 'daily_365';
    case Monthly = 'monthly';
    case TwentyFourths = '24ths';

    /** @return list<string> */
    public static function supported(): array
    {
        return [self::Daily365->value, self::Monthly->value];
    }
}
