<?php

declare(strict_types=1);

namespace Tests\PHPStan;

use PhpParser\Node\Expr\FuncCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\FunctionReflection;
use PHPStan\Reflection\ParameterReflection;
use PHPStan\Type\FunctionParameterClosureThisExtension;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use Tests\TestCase;

/**
 * Pest annotates test closures with `@param-closure-this TestCall`, but runs them bound to the test
 * case (tests/Pest.php: Tests\TestCase). This tells PHPStan the runtime truth, so `$this` in a test is
 * a TestCase and state shared from beforeEach is read as TestCase properties.
 */
final class PestClosureThisExtension implements FunctionParameterClosureThisExtension
{
    private const TEST_FUNCTIONS = ['it', 'test', 'beforeeach', 'aftereach'];

    public function isFunctionSupported(FunctionReflection $functionReflection, ParameterReflection $parameter): bool
    {
        return $parameter->getName() === 'closure'
            && in_array(strtolower($functionReflection->getName()), self::TEST_FUNCTIONS, true);
    }

    public function getClosureThisTypeFromFunctionCall(FunctionReflection $functionReflection, FuncCall $functionCall, ParameterReflection $parameter, Scope $scope): Type
    {
        return new ObjectType(TestCase::class);
    }
}
