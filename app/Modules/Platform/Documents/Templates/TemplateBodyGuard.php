<?php

declare(strict_types=1);

namespace App\Modules\Platform\Documents\Templates;

/**
 * Keeps a tenant-edited Blade body to a safe subset before it is ever compiled (slice R8). Blade compiles `{{ … }}` and directive arguments to
 * PHP, so a template body is PHP code in disguise: only a whitelist is accepted. ASSUMPTION: A-100 (what the whitelist holds).
 *
 * - Output: `{{ $name }}`, `{{ $name['key'] }}` (any depth), optionally `?? 'fallback'`. Always escaped. `{!! !!}` is refused.
 * - Directives: `@if(…)`, `@elseif(…)`, `@else`, `@endif`, `@foreach($list as $item)`, `@endforeach`. Conditions are variables, `!`, comparisons
 *   with a variable, a quoted string or a whole number, joined by `&&` / `||`. `@@` writes a literal `@`.
 * - Variables: only the documented variables of the template (and the loop variables it names).
 * - Refused outright: PHP tags, any other directive (`@php`, `@include`, `@inject`, `@extends`, `@component`, …), Blade components (`<x-…>`),
 *   scripts, frames, objects, forms, `<link>`/`<meta>`/`<base>`, event handler attributes, `javascript:` URLs, `@import`, and references to files or
 *   remote addresses (`file:`, `http(s):`, `//host` in src, href or url()) — the PDF renderer is offline and reads nothing but the document.
 */
final class TemplateBodyGuard
{
    private const DIRECTIVES_WITH_ARGUMENTS = ['if', 'elseif', 'foreach'];

    private const DIRECTIVES_WITHOUT_ARGUMENTS = ['else', 'endif', 'endforeach'];

    /** CSS at-rules that may appear in a <style> block of the body or letterhead. */
    private const CSS_AT_RULES = ['media', 'page'];

    private const NAME = '[a-z][a-z0-9_]*';

    /**
     * Every problem found, in the order found; an empty list means the body is safe to compile.
     *
     * @param list<string> $variables allowed top-level variable names (without `$`)
     * @return list<string>
     */
    public static function problems(string $body, array $variables): array
    {
        $problems = [];
        // Blade drops comments before compiling anything, so the checks read the body without them; an unclosed comment hides nothing.
        $withoutComments = preg_replace('/\{\{--.*?--\}\}/s', '', $body) ?? '';
        if (str_contains($withoutComments, '{{--')) {
            $problems[] = 'A comment {{-- is not closed with --}}.';
        }
        $refuse = function (string $pattern, string $message) use ($withoutComments, &$problems): void {
            if (preg_match($pattern, $withoutComments) === 1) {
                $problems[] = $message;
            }
        };
        $refuse('/<\?/', 'PHP tags (<?php, <?=) are not allowed in a template.');
        $refuse('/\{!!|!!\}/', 'Unescaped output {!! !!} is not allowed; use {{ $variable }}.');
        $refuse('/\{\{\{/', 'Triple braces {{{ are not allowed; use {{ $variable }}.');
        $refuse('/<\s*\/?\s*x[-:]/i', 'Blade components (<x-…>) are not allowed in a template.');
        $refuse('/<\s*(script|iframe|frame|frameset|object|embed|applet|form|input|button|textarea|link|meta|base|svg|math)\b/i',
            'Scripts, frames, embedded objects, forms, SVG and <link>, <meta> or <base> tags are not allowed in a template.');
        $refuse('/\bon[a-z]+\s*=/i', 'Event handler attributes such as onclick= are not allowed in a template.');
        $refuse('/javascript\s*:|vbscript\s*:/i', 'javascript: links are not allowed in a template.');
        $refuse('/@import\b/i', 'CSS @import is not allowed in a template.');
        $refuse('/file\s*:/i', 'References to files (file:) are not allowed in a template.');
        $refuse('/(?:src|href|srcset|action|poster|background)\s*=\s*["\']?\s*(?:[a-z][a-z0-9+.-]*:(?<!data:)|\/\/)|url\(\s*["\']?\s*(?:[a-z][a-z0-9+.-]*:(?<!data:)|\/\/)/i',
            'Links to other addresses are not allowed in a template: the document is printed offline. Embed images as data: URIs.');


        $loopVariables = [];
        if (preg_match_all('/@foreach\s*\(\s*\$'.self::NAME.'(?:\[[^\]]*\])*\s+as\s+\$('.self::NAME.')\s*\)/', $withoutComments, $loops) > 0) {
            $loopVariables = $loops[1];
        }
        $allowed = array_values(array_unique([...$variables, ...$loopVariables]));

        // Echoes.
        $remaining = preg_replace_callback('/\{\{(.*?)\}\}/s', function (array $match) use (&$problems, $allowed): string {
            $expression = trim($match[1]);
            if (! self::isEcho($expression)) {
                $problems[] = 'Only variables can be printed: {{ '.self::excerpt($expression).' }} is not allowed. Write {{ $name }} or {{ $name[\'key\'] }}.';
            } else {
                self::checkVariables($expression, $allowed, $problems);
            }

            return '';
        }, $withoutComments) ?? '';
        if (str_contains($remaining, '{{') || str_contains($remaining, '}}')) {
            $problems[] = 'An output tag {{ is not closed with }} (or }} has no opening {{).';
        }

        // Directives, found the way Blade finds them: on the text with its output tags still in place (Blade compiles statements first).
        $depth = ['if' => 0, 'foreach' => 0];
        $offset = 0;
        while (preg_match('/\B@(@?)(\w+)/', $withoutComments, $match, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $at = (int) $match[0][1];
            $name = strtolower($match[2][0]);
            $offset = $at + strlen($match[0][0]);
            if ($match[1][0] === '@' || in_array($name, self::CSS_AT_RULES, true)) {
                continue; // @@name is a literal @name; @media and @page are CSS.
            }
            if (in_array($name, self::DIRECTIVES_WITHOUT_ARGUMENTS, true)) {
                match ($name) {
                    'endif' => $depth['if']--,
                    'endforeach' => $depth['foreach']--,
                    default => null,
                };
                if ($depth['if'] < 0 || $depth['foreach'] < 0) {
                    $problems[] = "@{$name} has no matching opening directive.";
                    $depth = ['if' => max(0, $depth['if']), 'foreach' => max(0, $depth['foreach'])];
                }

                continue;
            }
            if (! in_array($name, self::DIRECTIVES_WITH_ARGUMENTS, true)) {
                $problems[] = "@{$name} is not allowed in a template. Use @if, @elseif, @else, @endif, @foreach and @endforeach only (write @@ for a literal @).";

                continue;
            }
            $parsed = self::arguments($withoutComments, $offset);
            if ($parsed === null) {
                $problems[] = "@{$name} needs its condition in brackets, like @{$name}(\$name).";

                continue;
            }
            [$arguments, $offset] = $parsed;
            $expression = trim($arguments);
            $valid = $name === 'foreach' ? self::isLoop($expression) : self::isCondition($expression);
            if (! $valid) {
                $problems[] = $name === 'foreach'
                    ? '@foreach('.self::excerpt($expression).') is not allowed. Write @foreach($list as $item).'
                    : "@{$name}(".self::excerpt($expression).') is not allowed. Conditions compare variables, quoted text or whole numbers, like @if($name[\'key\'] == \'yes\').';
            } else {
                self::checkVariables($expression, $allowed, $problems);
            }
            if ($name !== 'elseif') {
                $depth[$name]++;
            }
        }
        if ($depth['if'] > 0) {
            $problems[] = '@if is not closed with @endif.';
        }
        if ($depth['foreach'] > 0) {
            $problems[] = '@foreach is not closed with @endforeach.';
        }

        return array_values(array_unique($problems));
    }

    /**
     * The bracketed arguments right after a directive (skipping spaces), with nested brackets and quoted strings, and the offset after the closing
     * bracket; null when there are none.
     *
     * @return array{0: string, 1: int}|null
     */
    private static function arguments(string $text, int $offset): ?array
    {
        $length = strlen($text);
        $i = $offset;
        while ($i < $length && ($text[$i] === ' ' || $text[$i] === "\t")) {
            $i++;
        }
        if ($i >= $length || $text[$i] !== '(') {
            return null;
        }
        $depth = 0;
        $quote = null;
        for ($j = $i; $j < $length; $j++) {
            $char = $text[$j];
            if ($quote !== null) {
                if ($char === $quote) {
                    $quote = null;
                }

                continue;
            }
            if ($char === '\'' || $char === '"') {
                $quote = $char;
            } elseif ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
                if ($depth === 0) {
                    return [substr($text, $i + 1, $j - $i - 1), $j + 1];
                }
            }
        }

        return null;
    }

    private static function access(): string
    {
        return '\$'.self::NAME.'(?:\[\s*(?:\'[A-Za-z0-9_]+\'|\d+)\s*\])*';
    }

    private static function literal(): string
    {
        // Quoted text holds no brackets, braces, quotes, backslashes, @, $, < or >, so Blade and this guard always agree where an argument ends.
        return '(?:\'[^\'\\\\\n(){}@$<>?]*\'|-?\d+|true|false|null)';
    }

    private static function isEcho(string $expression): bool
    {
        return preg_match('/^'.self::access().'(?:\s*\?\?\s*(?:'.self::access().'|'.self::literal().'))?$/', $expression) === 1;
    }

    private static function isCondition(string $expression): bool
    {
        $operand = '(?:'.self::access().'|'.self::literal().')';
        $term = '!?\s*'.self::access().'(?:\s*(?:===|!==|==|!=|>=|<=|>|<)\s*'.$operand.')?';

        return preg_match('/^'.$term.'(?:\s*(?:&&|\|\|)\s*'.$term.')*$/', $expression) === 1;
    }

    private static function isLoop(string $expression): bool
    {
        return preg_match('/^'.self::access().'\s+as\s+\$'.self::NAME.'$/', $expression) === 1;
    }

    /**
     * @param list<string> $allowed
     * @param list<string> $problems
     */
    private static function checkVariables(string $expression, array $allowed, array &$problems): void
    {
        $withoutStrings = preg_replace('/\'[^\']*\'/', "''", $expression) ?? '';
        preg_match_all('/\$('.self::NAME.')/', $withoutStrings, $names);
        foreach ($names[1] as $name) {
            if (! in_array($name, $allowed, true)) {
                $problems[] = "\${$name} is not a variable of this template. See the variables list.";
            }
        }
    }

    private static function excerpt(string $expression): string
    {
        $text = (string) preg_replace('/\s+/', ' ', $expression);

        return mb_strlen($text) > 60 ? mb_substr($text, 0, 57).'…' : $text;
    }
}
