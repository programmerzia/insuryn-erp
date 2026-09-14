<?php

declare(strict_types=1);

namespace App\Http\Feedback;

use App\Modules\Insurance\Product\Domain\Enums\RiskFieldType;
use App\Modules\Insurance\Product\Domain\Risk\RiskField;
use App\Modules\Insurance\Product\Domain\Risk\RiskInputsInvalid;
use App\Modules\Platform\Money\MinorUnits;
use App\Modules\Platform\Preferences\UserPreferences;
use Illuminate\Http\Request;

/**
 * Follow-up H2 (UX brief §4 "Errors say what happened and what to do"): risk schema problems in words, per field, with the schema's labels, in the language the
 * user reads the risk form in (their `locale` preference: English or Bangla). The English sentences are those of the browser's own checks
 * (resources/js/lib/riskForm.ts `problemMessage`), so a problem reads the same whether the browser or the server found it.
 * ASSUMPTION A-161: the Bangla wording (Latin digits, as on Bangla documents, A-104) is ours, to verify with the customer.
 */
final class RiskProblems
{
    public const REASON = 'RISK_INPUTS_INVALID';

    /**
     * Field key → sentence, in the order the schema reported them.
     *
     * @return array<string, string>
     */
    public static function fields(RiskInputsInvalid $invalid, string $locale): array
    {
        $sentences = [];
        foreach ($invalid->errors as $key => $code) {
            $sentences[$key] = self::sentence($key, $code, $invalid->schema?->field($key), $locale);
        }

        return $sentences;
    }

    /** One line for the top of the form: which details to check, by label. */
    public static function summary(RiskInputsInvalid $invalid, string $locale): string
    {
        $labels = array_map(fn (string $key): string => self::label($key, $invalid->schema?->field($key), $locale), array_keys($invalid->errors));

        return $locale === 'bn' ? 'ঝুঁকির বিবরণ দেখুন: '.implode(', ', $labels).'।' : 'Check the risk details: '.implode(', ', $labels).'.';
    }

    /**
     * The JSON refusal: the reason, the summary as `message`, the problem codes per field as `errors` (unchanged for API clients) and the sentences as `fields`.
     *
     * @return array{reason: string, message: string, errors: array<string, string>, fields: array<string, string>}
     */
    public static function json(RiskInputsInvalid $invalid, string $locale): array
    {
        return ['reason' => self::REASON, 'message' => self::summary($invalid, $locale), 'errors' => $invalid->errors, 'fields' => self::fields($invalid, $locale)];
    }

    /**
     * The browser form refusal: `form` (the summary), `reason`, and `risk_inputs.<key>` per field, as the risk forms read them.
     *
     * @param array<string, string> $fields key → sentence
     * @return array<string, string>
     */
    public static function formErrors(string $summary, array $fields): array
    {
        $errors = ['form' => $summary, 'reason' => self::REASON];
        foreach ($fields as $key => $sentence) {
            $errors["risk_inputs.{$key}"] = $sentence;
        }

        return $errors;
    }

    /** @return array<string, string> */
    public static function formErrorsFor(RiskInputsInvalid $invalid, string $locale): array
    {
        return self::formErrors(self::summary($invalid, $locale), self::fields($invalid, $locale));
    }

    /** The signed-in user's form language: `bn` or `en` (the default, also for guests). */
    public static function locale(Request $request): string
    {
        $user = $request->user();
        if ($user === null) {
            return 'en';
        }

        return app(UserPreferences::class)->of((string) $user->getAuthIdentifier())['locale'] === 'bn' ? 'bn' : 'en';
    }

    private static function sentence(string $key, string $code, ?RiskField $field, string $locale): string
    {
        $bn = $locale === 'bn';
        $show = fn (?int $n): string => $n === null || $field === null ? '' : ($field->type === RiskFieldType::Money ? MinorUnits::format($n, 'BDT')
            : (str_starts_with($field->key, 'year') ? (string) $n : number_format($n)));
        $label = self::label($key, $field, $locale);

        return match ($code) {
            'REQUIRED' => $field?->type === RiskFieldType::Select
                ? ($bn ? "{$label} বেছে নিন।" : "Choose the {$label}.")
                : ($bn ? "{$label} লিখুন।" : "Enter the {$label}."),
            'NOT_INTEGER' => $field?->type === RiskFieldType::Money
                ? ($bn ? 'টাকার অঙ্ক লিখুন, যেমন 1,234,567.00।' : 'Enter an amount, like 1,234,567.00.')
                : ($bn ? 'একটি পূর্ণ সংখ্যা লিখুন।' : 'Enter a whole number.'),
            'BELOW_MIN' => $field?->min === null ? ($bn ? 'মানটি খুব কম।' : 'The value is too low.') : ($bn ? "কমপক্ষে {$show($field->min)} লিখুন।" : "Enter at least {$show($field->min)}."),
            'ABOVE_MAX' => $field?->max === null ? ($bn ? 'মানটি খুব বেশি।' : 'The value is too high.') : ($bn ? "সর্বোচ্চ {$show($field->max)} লিখুন।" : "Enter at most {$show($field->max)}."),
            'NOT_AN_OPTION' => $bn ? 'তালিকা থেকে একটি বেছে নিন।' : 'Choose one of the options.',
            'NOT_A_DATE' => $bn ? '15 Sep 2026-এর মতো একটি তারিখ লিখুন।' : 'Enter a date like 15 Sep 2026.',
            'TOO_LONG' => $bn ? ($field->maxLength ?? 255).' অক্ষরের মধ্যে রাখুন।' : 'Keep it to '.($field->maxLength ?? 255).' characters.',
            'UNKNOWN_FIELD' => $bn ? 'এই পণ্যে এই তথ্য লাগে না।' : 'This product does not ask for this.',
            'NOT_BOOLEAN' => $bn ? 'বাক্সে টিক দিন বা টিক তুলে দিন।' : 'Tick or clear the box.',
            default => $bn ? 'মানটি যাচাই করুন।' : 'Check this value.',
        };
    }

    /** The field's label in the language (English lower-cased inside a sentence); a field the schema does not have is named by its key in words. */
    private static function label(string $key, ?RiskField $field, string $locale): string
    {
        if ($field === null) {
            return str_replace('_', ' ', $key);
        }
        if ($locale === 'bn') {
            return $field->labelBn;
        }

        return mb_strtolower($field->labelEn);
    }
}
