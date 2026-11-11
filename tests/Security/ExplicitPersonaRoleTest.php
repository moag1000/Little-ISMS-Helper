<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Tenant;
use App\Entity\User;
use App\Lifecycle\EntityTypeRegistry;
use App\Security\Authority\ExplicitRole;
use App\Security\Voter\LifecycleVoter;
use App\Tests\Contract\Support\ContractRecordFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Workflow\Registry;

/**
 * S28 (F-10): the decisions of the data protection officer and of top management belong to
 * the people who hold those roles. ROLE_ADMIN does not inherit ROLE_DPO (as it does not
 * inherit ROLE_TOP_MGMT), and a transition reserved for them ({@see ExplicitRole::isReservedStep()}:
 * names one of them and no broad role) is closed for an admin without it — also through
 * the roles ROLE_ADMIN inherits (a breach notification "DPO or CISO").
 *
 * Transitions are found from {@see EntityTypeRegistry} and the workflow definitions; a new
 * DPO or top-management transition is covered the day it is added.
 */
final class ExplicitPersonaRoleTest extends KernelTestCase
{
    #[Test]
    public function anAdminWithoutTheRoleCannotTakeTheStep(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $security = $container->get(Security::class);
        $workflows = $container->get(Registry::class);

        $id = bin2hex(random_bytes(4));
        $tenant = (new Tenant())->setName('Persona ' . $id)->setCode('persona_' . $id);
        $em->persist($tenant);
        $admin = $this->user($em, $tenant, 'admin-' . $id, ['ROLE_ADMIN']);
        $superAdmin = $this->user($em, $tenant, 'super-' . $id, ['ROLE_SUPER_ADMIN']);
        $holders = [];
        foreach (ExplicitRole::ASSIGNED_ONLY_STEP_ROLES as $role) {
            // A holder in the organisation, so nobody is responsible as admin of last resort.
            $holders[$role] = $this->user($em, $tenant, strtolower($role) . '-' . $id, [$role]);
        }
        $em->flush();

        self::assertFalse($security->isGrantedForUser($admin, 'ROLE_DPO'), 'ROLE_ADMIN must not inherit ROLE_DPO (S28)');
        self::assertFalse($security->isGrantedForUser($admin, 'ROLE_TOP_MGMT'), 'ROLE_ADMIN must not inherit ROLE_TOP_MGMT');

        $registry = new EntityTypeRegistry();
        $checked = [];
        foreach ($registry->knownSlugs() as $slug) {
            $entry = $registry->lookup($slug);
            self::assertNotNull($entry);
            $probe = (new \ReflectionClass($entry['class']))->newInstanceWithoutConstructor();
            $workflow = $workflows->get($probe, $entry['workflow']);
            foreach ($workflow->getDefinition()->getTransitions() as $transition) {
                $roles = (array) ($workflow->getMetadataStore()->getTransitionMetadata($transition)['roles'] ?? []);
                if (!ExplicitRole::isReservedStep($roles)) {
                    continue; // not a DPO / top-management step, or open to every manager anyway
                }
                $named = array_values(array_intersect($roles, ExplicitRole::ASSIGNED_ONLY_STEP_ROLES));
                try {
                    $subject = (new ContractRecordFactory($em))->create($entry['class'], $tenant, 'persona probe');
                } catch (\Throwable $e) {
                    self::fail(sprintf('%s not buildable: %s', $entry['class'], $e->getMessage()));
                }
                $attribute = LifecycleVoter::ATTRIBUTE_PREFIX . $entry['workflow'] . '.' . $transition->getName();
                $label = $entry['workflow'] . '.' . $transition->getName();
                self::assertFalse($security->isGrantedForUser($admin, $attribute, $subject), $label . ': open for ROLE_ADMIN');
                self::assertFalse($security->isGrantedForUser($superAdmin, $attribute, $subject), $label . ': open for ROLE_SUPER_ADMIN');
                self::assertTrue(
                    $security->isGrantedForUser($holders[$named[0]], $attribute, $subject),
                    $label . ': closed for the holder of ' . $named[0],
                );
                $checked[$label] = true;
            }
        }

        foreach (['data_breach_lifecycle.notify_authority', 'data_subject_request_lifecycle.complete', 'consent_lifecycle.verify', 'management_decision_lifecycle.approve'] as $must) {
            self::assertArrayHasKey($must, $checked, 'Not discovered: ' . $must . ' (checked: ' . implode(', ', array_keys($checked)) . ')');
        }
    }

    /** @param list<string> $roles */
    private function user(EntityManagerInterface $em, Tenant $tenant, string $name, array $roles): User
    {
        $user = (new User())
            ->setEmail($name . '@example.test')
            ->setFirstName('Persona')
            ->setLastName($name)
            ->setRoles($roles)
            ->setPassword('hashed')
            ->setTenant($tenant)
            ->setIsActive(true);
        $em->persist($user);

        return $user;
    }
}
