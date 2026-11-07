<?php

declare(strict_types=1);

namespace App\Tests\Stub;

use App\Security\Persona\PersonaResolver;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Role\RoleHierarchy;
use Symfony\Component\Yaml\Yaml;

/**
 * PersonaResolver for unit tests, on the real role hierarchy from
 * config/packages/security.yaml — so a test cannot drift from production.
 */
final class PersonaResolverFactory
{
    public static function create(?Security $security = null, ?RequestStack $requestStack = null): PersonaResolver
    {
        return new PersonaResolver(new RoleHierarchy(self::hierarchy()), $security, $requestStack);
    }

    /** @return array<string, list<string>> */
    public static function hierarchy(): array
    {
        /** @var array{security: array{role_hierarchy: array<string, string|list<string>>}} $config */
        $config = Yaml::parseFile(dirname(__DIR__, 2) . '/config/packages/security.yaml');

        return array_map(
            static fn (string|array $children): array => array_values((array) $children),
            $config['security']['role_hierarchy'],
        );
    }
}
