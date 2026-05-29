<?php

declare(strict_types=1);

namespace App\Tests\PHPStan\Type;

use App\Entity\User;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Type\DynamicMethodReturnTypeExtension;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;

/**
 * Narrows getUser() to App\Entity\User|null.
 *
 * Symfony declares getUser(): ?UserInterface on both the SecurityBundle Security
 * service and AbstractController. This app has exactly one user entity, so every
 * `$this->security->getUser()?->getTenant()` (and the ~190 similar calls) would
 * otherwise be a false method.notFound against the interface. phpstan-symfony
 * does NOT provide this resolution, so we add it ourselves.
 *
 * Registered once per supported class via the service definitions in
 * phpstan.dist.neon (the $forClass constructor argument).
 */
final class GetUserReturnTypeExtension implements DynamicMethodReturnTypeExtension
{
    public function __construct(private readonly string $forClass)
    {
    }

    public function getClass(): string
    {
        return $this->forClass;
    }

    public function isMethodSupported(MethodReflection $methodReflection): bool
    {
        return $methodReflection->getName() === 'getUser';
    }

    public function getTypeFromMethodCall(
        MethodReflection $methodReflection,
        MethodCall $methodCall,
        Scope $scope,
    ): ?Type {
        // getUser() may legitimately return null (unauthenticated) — keep it nullable.
        return TypeCombinator::addNull(new ObjectType(User::class));
    }
}
