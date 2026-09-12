<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application;

use App\Modules\Accounting\Domain\PostingRule;
use Carbon\CarbonImmutable;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;

final class PostingRuleRepository
{
    /** @var list<PostingRule>|null */
    private ?array $rules = null;

    public function __construct(private readonly string $path, private readonly ExpressionLanguage $expr) {}

    /** @return list<PostingRule> */
    public function all(): array
    {
        if ($this->rules === null) {
            $this->rules = [];
            foreach (glob($this->path.'/*.json') ?: [] as $file) {
                /** @var array<string,mixed> $json */
                $json = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
                $this->rules[] = PostingRule::fromArray($json);
            }
        }
        return $this->rules;
    }

    /**
     * Design §3.3 step 2: type match, applies_to match, condition true, effective, highest specificity,
     * latest version. Ambiguity (two rules, same specificity, same version tie) is an error, not a guess.
     *
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $dimensions
     */
    public function resolve(string $eventType, CarbonImmutable $effectiveDate, array $payload, array $dimensions): PostingRule
    {
        $candidates = [];
        foreach ($this->all() as $rule) {
            if ($rule->eventType !== $eventType || ! $rule->isEffectiveOn($effectiveDate)) {
                continue;
            }
            if (! $this->appliesTo($rule, $dimensions)) {
                continue;
            }
            if ($rule->condition !== null && ! (bool) $this->expr->evaluate($rule->condition, ['payload' => $payload, 'dims' => $dimensions])) {
                continue;
            }
            $candidates[] = $rule;
        }
        if ($candidates === []) {
            throw new \App\Modules\Accounting\Exceptions\PostingFailedException('NO_RULE', "No posting rule for {$eventType}");
        }
        usort($candidates, fn (PostingRule $a, PostingRule $b) => [$b->specificity(), $b->version] <=> [$a->specificity(), $a->version]);
        if (count($candidates) > 1 && $candidates[0]->specificity() === $candidates[1]->specificity() && $candidates[0]->version === $candidates[1]->version) {
            throw new \App\Modules\Accounting\Exceptions\PostingFailedException('AMBIGUOUS_RULE', "Ambiguous rules for {$eventType}: {$candidates[0]->code}, {$candidates[1]->code}");
        }
        return $candidates[0];
    }

    /** @param array<string,mixed> $dims */
    private function appliesTo(PostingRule $rule, array $dims): bool
    {
        $map = ['product_codes' => 'product_code', 'lob' => 'lob', 'channels' => 'channel'];
        foreach ($map as $ruleKey => $dimKey) {
            $allowed = $rule->appliesTo[$ruleKey] ?? ['*'];
            if ($allowed === ['*']) {
                continue;
            }
            if (! in_array($dims[$dimKey] ?? null, $allowed, true)) {
                return false;
            }
        }
        return true;
    }
}
