<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Rating\Domain\Expressions;

use App\Modules\Insurance\Rating\Domain\RatingFailed;
use ArgumentCountError;
use DivisionByZeroError;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;
use Symfony\Component\ExpressionLanguage\Node\BinaryNode;
use Symfony\Component\ExpressionLanguage\Node\ConstantNode;
use Symfony\Component\ExpressionLanguage\Node\FunctionNode;
use Symfony\Component\ExpressionLanguage\Node\GetAttrNode;
use Symfony\Component\ExpressionLanguage\Node\Node;
use Symfony\Component\ExpressionLanguage\Node\UnaryNode;
use Symfony\Component\ExpressionLanguage\ParsedExpression;
use Symfony\Component\ExpressionLanguage\SyntaxError;
use TypeError;

/**
 * The safe evaluator for rating steps (Phase 3 design §1: "the same safe evaluator as posting rules"): Symfony ExpressionLanguage, its own instance with
 * RatingFunctions, so posting-rule behaviour is untouched. On top of the language, an expression is refused unless it keeps to integer arithmetic:
 * no `/`, `%`, `**`, string or bit operators, no decimal constants, no method calls, only the rating functions, and only the variables
 * `risk`, `coverage`, `sum_insured`, `running`, `steps`. An amount must evaluate to an integer, a condition to true or false.
 */
final class RatingExpressions
{
    public const VARIABLES = ['risk', 'coverage', 'sum_insured', 'running', 'steps'];

    private const OPERATORS = ['+', '-', '*', '==', '===', '!=', '!==', '<', '>', '<=', '>=', 'and', '&&', 'or', '||', 'not', '!', 'in', 'not in'];

    private readonly ExpressionLanguage $language;

    /** @var array<string, array{parsed: ParsedExpression, tables: list<array{function: string, table: string}>}> */
    private array $checked = [];

    public function __construct()
    {
        $this->language = new ExpressionLanguage(null, [new RatingFunctions()]);
    }

    /**
     * Parses and checks an expression.
     *
     * @return list<array{function: string, table: string}> the rate tables it names (lookup, band, band_value)
     *
     * @throws RatingFailed RATING_EXPRESSION_INVALID
     */
    public function check(string $expression): array
    {
        return $this->parsed($expression)['tables'];
    }

    /**
     * @param array<string, mixed> $variables risk, coverage, sum_insured, running, steps
     *
     * @throws RatingFailed
     */
    public function amount(string $expression, array $variables, RatingContext $context): int
    {
        $value = $this->evaluate($expression, $variables, $context);
        if (! is_int($value)) {
            throw new RatingFailed('RATING_EXPRESSION_NOT_INTEGER', "Expression '{$expression}' gave ".get_debug_type($value).'; amounts are whole minor units.');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $variables
     *
     * @throws RatingFailed
     */
    public function condition(string $expression, array $variables, RatingContext $context): bool
    {
        $value = $this->evaluate($expression, $variables, $context);
        if (! is_bool($value)) {
            throw new RatingFailed('RATING_CONDITION_NOT_BOOLEAN', "Condition '{$expression}' gave ".get_debug_type($value).', not true or false.');
        }

        return $value;
    }

    /** @param array<string, mixed> $variables */
    private function evaluate(string $expression, array $variables, RatingContext $context): mixed
    {
        $parsed = $this->parsed($expression)['parsed'];
        try {
            return $this->language->evaluate($parsed, [...$variables, RatingContext::VARIABLE => $context]);
        } catch (TypeError|ArgumentCountError $error) {
            throw new RatingFailed('RATING_EXPRESSION_INVALID', "Expression '{$expression}' uses a value of the wrong kind: ".$error->getMessage());
        } catch (DivisionByZeroError) {
            throw new RatingFailed('RATING_DIVISION_BY_ZERO', "Expression '{$expression}' divides by zero.");
        }
    }

    /** @return array{parsed: ParsedExpression, tables: list<array{function: string, table: string}>} */
    private function parsed(string $expression): array
    {
        if (isset($this->checked[$expression])) {
            return $this->checked[$expression];
        }
        try {
            $parsed = $this->language->parse($expression, self::VARIABLES);
        } catch (SyntaxError $error) {
            throw new RatingFailed('RATING_EXPRESSION_INVALID', "Expression '{$expression}' is not valid: ".$error->getMessage());
        }
        $tables = [];
        $this->inspect($parsed->getNodes(), $expression, $tables);

        return $this->checked[$expression] = ['parsed' => $parsed, 'tables' => $tables];
    }

    /** @param list<array{function: string, table: string}> $tables */
    private function inspect(Node $node, string $expression, array &$tables): void
    {
        $refuse = fn (string $why): RatingFailed => new RatingFailed('RATING_EXPRESSION_INVALID', "Expression '{$expression}' {$why}.");
        if (($node instanceof BinaryNode || $node instanceof UnaryNode) && ! in_array($node->attributes['operator'], self::OPERATORS, true)) {
            throw $refuse("uses the operator {$node->attributes['operator']}; use div(), pct() or per_mille() for division");
        }
        if ($node instanceof ConstantNode && is_float($node->attributes['value'])) {
            throw $refuse('has a decimal number; amounts are whole minor units and rates whole basis points');
        }
        if ($node instanceof GetAttrNode && $node->attributes['type'] !== GetAttrNode::PROPERTY_CALL) {
            throw $refuse('calls a method or indexes a value; read fields as risk.field');
        }
        if ($node instanceof FunctionNode) {
            $name = (string) $node->attributes['name'];
            if (! in_array($name, RatingFunctions::NAMES, true)) {
                throw $refuse("calls {$name}(), which is not a rating function");
            }
            if (isset(RatingFunctions::TABLE_ARGUMENT[$name])) {
                $argument = $node->nodes['arguments']->nodes[RatingFunctions::TABLE_ARGUMENT[$name]] ?? null;
                if (! $argument instanceof ConstantNode || ! is_string($argument->attributes['value'])) {
                    throw $refuse("must name the table of {$name}() in quotes");
                }
                $tables[] = ['function' => $name, 'table' => $argument->attributes['value']];
            }
        }
        foreach ($node->nodes as $child) {
            if ($child instanceof Node) {
                $this->inspect($child, $expression, $tables);
            }
        }
    }
}
