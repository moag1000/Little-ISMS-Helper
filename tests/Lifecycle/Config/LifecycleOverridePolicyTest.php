<?php

declare(strict_types=1);

namespace App\Tests\Lifecycle\Config;

use App\Lifecycle\Config\LifecycleOverridePolicy;
use App\Lifecycle\EntityTypeRegistry;
use App\Security\RoleNames;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;
use Symfony\Component\Workflow\Registry;

/**
 * S28 (F-05): an organisation override of a transition's roles only narrows the circle of
 * the YAML — for every workflow of {@see EntityTypeRegistry}, every transition and every
 * assignable role, with the real role hierarchy: whoever the override lets in, the YAML
 * let in too.
 */
final class LifecycleOverridePolicyTest extends KernelTestCase
{
    #[Test]
    public function anOverrideNeverLetsInSomebodyTheYamlKeptOut(): void
    {
        self::bootKernel();
        $hierarchy = static::getContainer()->get(RoleHierarchyInterface::class);
        $workflows = static::getContainer()->get(Registry::class);
        $registry = new EntityTypeRegistry();

        $checked = 0;
        foreach ($registry->knownSlugs() as $slug) {
            $entry = $registry->lookup($slug);
            self::assertNotNull($entry);
            if (!EntityTypeRegistry::acceptsTenantOverrides($entry['workflow'])) {
                continue;
            }
            $subject = (new \ReflectionClass($entry['class']))->newInstanceWithoutConstructor();
            $workflow = $workflows->get($subject, $entry['workflow']);
            foreach ($workflow->getDefinition()->getTransitions() as $transition) {
                $yaml = $workflow->getMetadataStore()->getTransitionMetadata($transition);
                $yamlRoles = array_values(array_filter((array) ($yaml['roles'] ?? []), 'is_string'));
                foreach (LifecycleOverridePolicy::assignableRoles() as $role) {
                    $accepted = LifecycleOverridePolicy::violations($yaml, 'roles', [$role], $hierarchy) === [];
                    $label = sprintf('%s.%s: [%s] over [%s]', $entry['workflow'], $transition->getName(), $role, implode(', ', $yamlRoles));
                    if (!$accepted) {
                        continue;
                    }
                    // Every person the override admits (anybody reaching $role) was admitted by the YAML.
                    foreach (RoleNames::RESERVED as $held) {
                        $reached = $hierarchy->getReachableRoleNames([$held]);
                        if (in_array($role, $reached, true)) {
                            self::assertNotSame([], array_intersect($reached, $yamlRoles), $label . ' admits ' . $held);
                        }
                    }
                    ++$checked;
                }
                if ($yamlRoles === []) {
                    self::assertNotSame([], LifecycleOverridePolicy::violations($yaml, 'roles', ['ROLE_ADMIN'], $hierarchy), sprintf(
                        '%s.%s is service-only; an override must not give it roles',
                        $entry['workflow'],
                        $transition->getName(),
                    ));
                }
            }
        }
        self::assertGreaterThan(50, $checked, 'Too few accepted overrides checked — is the registry empty?');
    }

    #[Test]
    public function aManagerCannotReplaceTheCiso(): void
    {
        self::bootKernel();
        $hierarchy = static::getContainer()->get(RoleHierarchyInterface::class);
        $ciso = ['roles' => ['ROLE_CISO']];
        $manager = ['roles' => ['ROLE_MANAGER']];

        self::assertSame(
            ['admin.lifecycle_overrides.violation.roles_wider'],
            LifecycleOverridePolicy::violations($ciso, 'roles', ['ROLE_MANAGER'], $hierarchy),
        );
        self::assertSame([], LifecycleOverridePolicy::violations($manager, 'roles', ['ROLE_CISO'], $hierarchy));
        self::assertSame([], LifecycleOverridePolicy::effective($ciso, ['roles' => ['ROLE_MANAGER']], $hierarchy));
        // Without the hierarchy (unit fixtures) only the YAML roles themselves narrow.
        self::assertNotSame([], LifecycleOverridePolicy::violations($manager, 'roles', ['ROLE_CISO']));
    }
}
