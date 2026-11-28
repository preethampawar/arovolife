<?php

declare(strict_types=1);

namespace Tests\PHPStan;

use PhpParser\Node\Expr\FuncCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\FunctionReflection;
use PHPStan\Reflection\ParameterReflection;
use PHPStan\Type\DynamicFunctionReturnTypeExtension;
use PHPStan\Type\FunctionParameterClosureThisExtension;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use Tests\TestCase;

/**
 * Corrects how PHPStan types $this inside Pest test closures.
 *
 * Pest declares `@param-closure-this TestCall` on it(), test(), beforeEach()
 * and afterEach(), but at runtime it binds each closure to the test case
 * (Tests\TestCase, via pest()->extend() in tests/Pest.php). The wrong type
 * made every $this->actingAs(), ->get(), ->seed() … an "undefined method on
 * TestCall" error. A bare test() returns a proxy that forwards to the running
 * test case, so it is typed as the test case too.
 *
 * This is an extension rather than a stub because PHPStan validates function
 * stubs without the project's classes, and those errors cannot be ignored.
 */
final class PestTestCaseTypeExtension implements DynamicFunctionReturnTypeExtension, FunctionParameterClosureThisExtension
{
    private const CLOSURE_FUNCTIONS = ['it', 'test', 'beforeeach', 'aftereach'];

    public function isFunctionSupported(FunctionReflection $functionReflection, ?ParameterReflection $parameter = null): bool
    {
        $name = strtolower($functionReflection->getName());

        if ($parameter === null) {
            return $name === 'test';
        }

        return in_array($name, self::CLOSURE_FUNCTIONS, true) && $parameter->getName() === 'closure';
    }

    public function getClosureThisTypeFromFunctionCall(FunctionReflection $functionReflection, FuncCall $functionCall, ParameterReflection $parameter, Scope $scope): Type
    {
        return new ObjectType(TestCase::class);
    }

    public function getTypeFromFunctionCall(FunctionReflection $functionReflection, FuncCall $functionCall, Scope $scope): ?Type
    {
        return $functionCall->getArgs() === [] ? new ObjectType(TestCase::class) : null;
    }
}
