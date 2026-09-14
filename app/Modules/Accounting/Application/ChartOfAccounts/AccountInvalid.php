<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\ChartOfAccounts;

use App\Modules\Platform\Exceptions\BusinessRuleViolation;

/** An account the chart-of-accounts rules refuse, with one message per field (code, name, type, normal_side, parent_id, control_subledger, currency). */
final class AccountInvalid extends BusinessRuleViolation
{
    /** @param array<string, string> $errors field => message */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('ACCOUNT_INVALID', 'The account is not valid: '.implode(' ', $errors));
    }
}
