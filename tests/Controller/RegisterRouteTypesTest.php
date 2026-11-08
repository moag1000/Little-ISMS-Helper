<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\RegisterController;
use App\Enum\RegisterType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A new register is a new RegisterType case; the route requirement has to know it,
 * or the register overview fails to render its link.
 */
final class RegisterRouteTypesTest extends TestCase
{
    #[Test]
    public function routeRequirementListsEveryRegisterType(): void
    {
        $pattern = (string) (new \ReflectionClassConstant(RegisterController::class, 'TYPE_PATTERN'))->getValue();
        $routeTypes = explode('|', $pattern);
        $types = array_map(static fn (RegisterType $t): string => $t->value, RegisterType::cases());

        sort($routeTypes);
        sort($types);
        self::assertSame($types, $routeTypes);
    }
}
