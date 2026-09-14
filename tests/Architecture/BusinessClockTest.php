<?php

declare(strict_types=1);

/**
 * Slice 2.1b (DECISION D-54, CQ-H2): business dates come from App\Modules\Platform\Tenancy\BusinessClock, which follows the company's time
 * zone. The application clock (UTC) must not be read as a business date in the layers that decide or offer dates: every module's Http,
 * Application and Infrastructure code and the composition controllers in app/Http. Technical timestamps (`now()` for created_at, decided_at,
 * a reservation TTL) are allowed. Comments and strings used as array keys are ignored (PHP tokens, not text).
 *
 * @return list<string> "line: pattern" for every forbidden read of the application clock in the file
 */
function businessClockViolations(string $path): array
{
    $tokens = array_values(array_filter(token_get_all((string) file_get_contents($path)),
        fn (mixed $t): bool => ! is_array($t) || ! in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
    $text = fn (int $i): string => isset($tokens[$i]) ? (is_array($tokens[$i]) ? $tokens[$i][1] : $tokens[$i]) : '';
    $line = function (int $i) use ($tokens): int {
        for ($j = $i; $j >= 0; $j--) {
            if (is_array($tokens[$j])) {
                return $tokens[$j][2];
            }
        }

        return 0;
    };
    $found = [];
    foreach ($tokens as $i => $token) {
        if (! is_array($token)) {
            continue;
        }
        $value = strtolower($token[1]);
        $previous = $text($i - 1);
        $isName = in_array($token[0], [T_STRING, T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED], true);
        // CarbonImmutable::today(), Carbon::today(), Date::today(), \Carbon\CarbonImmutable::today()
        if ($isName && $value === 'today' && $previous === '::' && $text($i + 1) === '(') {
            $found[] = $line($i).': '.$text($i - 2).'::today()';
        }
        // the today() helper (a function call, not a method or a declaration)
        if ($isName && in_array(ltrim($value, '\\'), ['today'], true) && $text($i + 1) === '(' && ! in_array($previous, ['->', '?->', '::', 'function', 'new', 'const'], true)) {
            $found[] = $line($i).': today()';
        }
        // now()->toDateString(), now()->format('Y-m-d'), now()->startOfDay(), CarbonImmutable::now()->toDateString()
        if ($isName && ltrim($value, '\\') === 'now' && $text($i + 1) === '(' && $text($i + 2) === ')' && $text($i + 3) === '->'
            && in_array(strtolower($text($i + 4)), ['todatestring', 'format', 'startofday', 'startofmonth', 'endofmonth', 'submonth', 'submonthnooverflow'], true)) {
            $found[] = $line($i).': now()->'.$text($i + 4).'()';
        }
        // CarbonImmutable::parse('today'), ?? 'today', ?: 'today', query('on', 'today') — the string "today" or "now" as a date, not an array key
        if ($token[0] === T_CONSTANT_ENCAPSED_STRING && in_array(strtolower(trim($token[1], '\'"')), ['today', 'tomorrow', 'yesterday'], true)
            && $text($i + 1) !== '=>' && $previous !== '[') {
            $found[] = $line($i).': '.$token[1];
        }
    }

    return $found;
}

it('reads business dates from the BusinessClock, never from the application clock (D-54)', function (): void {
    $roots = [];
    foreach (glob(base_path('app/Modules/*/*'), GLOB_ONLYDIR) ?: [] as $dir) {
        $roots[] = $dir;
    }
    $roots[] = base_path('app/Http');
    $allowed = [base_path('app/Modules/Platform/Tenancy/BusinessClock.php')];

    $violations = [];
    $scanned = 0;
    foreach ($roots as $root) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
            $path = (string) $file;
            if (! str_ends_with($path, '.php') || in_array($path, $allowed, true)) {
                continue;
            }
            // Http, Application and Infrastructure of every module (including nested sub-modules such as Insurance/Claims/Http), and app/Http.
            $relative = substr($path, strlen(base_path()) + 1);
            if (! str_starts_with($relative, 'app/Http/') && preg_match('#/(Http|Application|Infrastructure)/#', $relative) !== 1) {
                continue;
            }
            $scanned++;
            foreach (businessClockViolations($path) as $violation) {
                $violations[] = "{$relative}:{$violation}";
            }
        }
    }

    expect($scanned)->toBeGreaterThan(200)
        ->and($violations)->toBe([]);
});

it('recognises the application-clock patterns it forbids', function (): void {
    $file = tempnam(sys_get_temp_dir(), 'clock').'.php';
    file_put_contents($file, <<<'PHP'
        <?php
        // CarbonImmutable::today() in a comment is fine
        $a = CarbonImmutable::today();
        $b = \Carbon\Carbon::today();
        $c = today();
        $d = now()->toDateString();
        $e = CarbonImmutable::parse($x ?? 'today');
        $ok = ['today' => $clock->today(), 'at' => now()];
        $this->today();
        PHP);
    $found = businessClockViolations($file);
    unlink($file);

    expect($found)->toHaveCount(5);
});
