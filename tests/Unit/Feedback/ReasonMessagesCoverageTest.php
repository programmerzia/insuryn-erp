<?php

declare(strict_types=1);

use App\Http\Feedback\ReasonMessages;

/*
 * Follow-up H2 (UX brief §4 "Errors say what happened and what to do"): every business-rule reason code thrown in app/ reaches people as a plain sentence.
 * A browser form shows ReasonMessages::forPeople(reason, domain message). A reason passes when ReasonMessages words it itself (`covers`), or when the domain
 * message at every place it is thrown is a written sentence with nothing a person cannot read: no record ids, no amounts in minor units, no reason codes,
 * snake_case keys or permission codes, and no technical text passed through (another exception's message, PHP type names).
 */

/**
 * Every place a reason code is thrown: `new BusinessRuleViolation('CODE', message)`, `new RatingFailed('CODE', message)`, `parent::__construct('CODE', message)`
 * in a subclass, and the subclasses' own constructors (`new RiskSchemaInvalid(message)`, `PlanDefinitionInvalid::because(message)`).
 *
 * @return list<array{where: string, codes: list<string>, first: string, message: list<array{0: int, 1: string}|string>}>
 */
function reasonThrowSites(): array
{
    $root = dirname(__DIR__, 3).'/app';
    $implied = ['RiskSchemaInvalid' => 'RISK_SCHEMA_INVALID', 'PlanDefinitionInvalid::because' => 'RATING_PLAN_INVALID'];
    $sites = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }
        $tokens = token_get_all((string) file_get_contents($file->getPathname()));
        $count = count($tokens);
        $text = fn (int $i): string => is_array($tokens[$i]) ? $tokens[$i][1] : $tokens[$i];
        $skip = function (int $i) use ($tokens, $count): int {
            while ($i < $count && is_array($tokens[$i]) && $tokens[$i][0] === T_WHITESPACE) {
                $i++;
            }

            return $i;
        };
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (! is_array($token)) {
                continue;
            }
            $kind = null;
            $open = $i;
            if ($token[0] === T_NEW) {
                $name = $skip($i + 1);
                $class = preg_replace('/^.*\\\\/', '', $text($name));
                if (in_array($class, ['BusinessRuleViolation', 'RatingFailed', 'RiskSchemaInvalid'], true)) {
                    $kind = $class;
                    $open = $skip($name + 1);
                }
            } elseif ($token[0] === T_STRING && $token[1] === 'parent' && $text($i + 1) === '::' && $text($i + 2) === '__construct') {
                $kind = 'parent';
                $open = $skip($i + 3);
            } elseif ($token[0] === T_STRING && $token[1] === 'PlanDefinitionInvalid' && $text($i + 1) === '::' && $text($i + 2) === 'because') {
                $kind = 'PlanDefinitionInvalid::because';
                $open = $skip($i + 3);
            }
            if ($kind === null || $text($open) !== '(') {
                continue;
            }
            $depth = 0;
            $args = [[]];
            for ($k = $open; $k < $count; $k++) {
                $piece = $text($k);
                if (in_array($piece, ['(', '[', '{', '{$', '${'], true) || (is_array($tokens[$k]) && in_array($tokens[$k][0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                    if (++$depth === 1) {
                        continue;
                    }
                } elseif (in_array($piece, [')', ']', '}'], true) && --$depth === 0) {
                    break;
                } elseif ($piece === ',' && $depth === 1) {
                    $args[] = [];
                    continue;
                }
                $args[count($args) - 1][] = is_array($tokens[$k]) ? [$tokens[$k][0], $tokens[$k][1]] : $tokens[$k];
            }
            $source = fn (array $arg): string => implode('', array_map(fn (array|string $t): string => is_array($t) ? $t[1] : $t, $arg));
            $where = substr($file->getPathname(), strlen($root) - 3).':'.$token[2];
            if (isset($implied[$kind])) {
                $sites[] = ['where' => $where, 'codes' => [$implied[$kind]], 'first' => $implied[$kind], 'message' => $args[0]];
                continue;
            }
            preg_match_all("/'([A-Z][A-Z0-9]*(?:_[A-Z0-9]+)+)'/", $source($args[0]), $codes);
            if ($kind === 'parent' && $codes[1] === []) {
                continue; // the parent constructor of another exception
            }
            $sites[] = ['where' => $where, 'codes' => $codes[1], 'first' => trim($source($args[0])), 'message' => $args[1] ?? []];
        }
    }

    return $sites;
}

/**
 * Why the message argument at a throw site is not a sentence people can read (empty when it is).
 *
 * @param list<array{0: int, 1: string}|string> $message
 * @return list<string>
 */
function reasonMessageProblems(array $message): array
{
    $problems = [];
    $literal = false;
    $expressions = '';
    foreach ($message as $token) {
        if (is_array($token) && in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
            $literal = true;
            foreach (['/\b[A-Z]{2,}(?:_[A-Z0-9]+)+\b/' => 'a code', '/\b[a-z]+(?:_[a-z0-9]+)+\b/' => 'a snake_case key', '/\b[a-z]{3,}\.[a-z_]{3,}\b/' => 'a permission code',
                '/minor unit/i' => 'minor units'] as $pattern => $what) {
                if (preg_match($pattern, $token[1], $found) === 1) {
                    $problems[] = "{$what} ({$found[0]})";
                }
            }
        } elseif (is_array($token)) {
            $expressions .= $token[1];
        } else {
            $expressions .= $token;
        }
    }
    if (! $literal) {
        $problems[] = 'no written sentence';
    }
    if (preg_match('/->id\b|\$\w*Id\b|\w_id\b/', $expressions, $found) === 1) {
        $problems[] = "a record id ({$found[0]})";
    }
    if (preg_match('/\$\w*[mM]inor\w*|->\w*_minor\b/', $expressions, $found) === 1 && preg_match('/MinorUnits\s*::\s*format|money\s*\(/', $expressions) !== 1) {
        $problems[] = "an amount in minor units ({$found[0]})";
    }
    if (preg_match('/getMessage|get_debug_type|json_encode|var_export/', $expressions, $found) === 1) {
        $problems[] = "technical text ({$found[0]})";
    }

    return $problems;
}

/** Throw sites whose reason code is a variable, with the codes it can hold (read from the code around them). A new one must be added here. */
const REASON_DYNAMIC_SITES = [
    'app/Modules/Platform/Administration/Http/UsersPageController.php' => ['self::reasonFor($violation)' => ['ROLE_CONFLICT', 'AUDITOR_WRITE_PERMISSION']],
    'app/Modules/Platform/Administration/Http/RolesPageController.php' => ['UsersPageController::reasonFor($violation)' => ['ROLE_CONFLICT', 'AUDITOR_WRITE_PERMISSION']],
    'app/Modules/Insurance/Underwriting/Application/ProposalService.php' => ['$reason' => ['PROPOSAL_NOT_DRAFT', 'PROPOSAL_NOT_APPROVED']],
    'app/Modules/Insurance/Quotation/Application/QuotationService.php' => ['$code' => ['QUOTATION_NOT_DRAFT', 'QUOTATION_NOT_OPEN']],
];

it('finds the throw sites', function (): void {
    $sites = reasonThrowSites();
    $codes = array_unique(array_merge(...array_column($sites, 'codes')));

    expect(count($sites))->toBeGreaterThan(300)->and(count($codes))->toBeGreaterThan(200)
        ->and($codes)->toContain('RISK_INPUTS_INVALID', 'CLAIM_NOT_PAID', 'RISK_SCHEMA_INVALID', 'RATING_PLAN_INVALID', 'RATING_OVERFLOW');
});

it('gives every business rule reason code thrown in app/ a plain-language message', function (): void {
    $refusals = [];
    $dynamic = [];
    foreach (reasonThrowSites() as $site) {
        $codes = $site['codes'];
        if ($codes === []) {
            $file = (string) preg_replace('/:\d+$/', '', $site['where']);
            $known = REASON_DYNAMIC_SITES[$file][$site['first']] ?? null;
            $dynamic[] = "{$file} {$site['first']}";
            if ($known === null) {
                $refusals[] = "{$site['where']}: the reason code {$site['first']} is not a literal; add the codes it can hold to REASON_DYNAMIC_SITES";
                continue;
            }
            $codes = $known;
        }
        foreach ($codes as $code) {
            if (ReasonMessages::covers($code)) {
                continue;
            }
            foreach (reasonMessageProblems($site['message']) as $problem) {
                $refusals[] = "{$code} at {$site['where']}: the message has {$problem}; word it for people in ReasonMessages or in the message";
            }
        }
    }

    expect($refusals)->toBe([])
        ->and(count($dynamic))->toBe(array_sum(array_map('count', REASON_DYNAMIC_SITES)));
});

it('writes its own messages for people, and rewords technical detail it keeps', function (): void {
    foreach (ReasonMessages::fixed() as $code => $sentence) {
        expect(preg_match('/\b[A-Z]{2,}(?:_[A-Z0-9]+)+\b|\b[a-z]+(?:_[a-z0-9]+)+\b|[0-9a-f]{8}-[0-9a-f]{4}-/', $sentence))->toBe(0, "{$code}: {$sentence}")
            ->and($sentence)->toEndWith('.');
    }

    expect(ReasonMessages::forPeople('RISK_INPUTS_INVALID', 'The risk details are not valid (chassis_no: REQUIRED).'))->toBe('Check the risk details: each field that needs attention is marked.')
        ->and(ReasonMessages::forPeople('ROLE_CONFLICT', 'Selim cannot hold platform.manage_roles together with accounting.approve_journal (segregation of duties). Remove one of the roles first.'))
        ->toBe('Selim cannot hold Platform: manage roles together with Accounting: approve journal (segregation of duties). Remove one of the roles first.')
        ->and(ReasonMessages::forPeople('RATING_PLAN_INVALID', 'A rating step needs an integer order_no and a snake_case code.'))->toBe('A rating step needs an integer order no and a snake case code.')
        ->and(ReasonMessages::forPeople('NOTHING_TO_PAY', 'Agent 01a0a1b2-0000-7000-8000-000000000001 has nothing payable up to 2026-09-30.'))->toBe('This agent has nothing payable up to 30 Sep 2026.')
        ->and(ReasonMessages::forPeople('PRODUCT_VERSION_NOT_EFFECTIVE', 'Product 01a0a1b2-0000-7000-8000-000000000001 has no version in force on 2025-06-01.'))
        ->toBe('The product has no version in force on 1 Jun 2025. Choose another cover start or product.')
        ->and(ReasonMessages::forPeople('RATING_EXPRESSION_NOT_INTEGER', "Expression 'risk.engine_cc / 3' gave float; amounts are whole minor units."))
        ->toBe("The formula 'risk.engine_cc / 3' must give a whole amount. Check it in the tariff editor.");
});
