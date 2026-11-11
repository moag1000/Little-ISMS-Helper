<?php

declare(strict_types=1);

namespace App\Tests\Lifecycle;

use App\Entity\Document;
use App\Entity\Tenant;
use App\Entity\User;
use App\EventSubscriber\ReadOnlyRoleSubscriber;
use App\Tests\Contract\Support\ContractRecordFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * S28 (F-09): a policy (ISO 27001 5.2) is approved and published by the CISO or top
 * management, not by every manager; other documents keep the roles of document_lifecycle.
 * Top management reaches the one-record lifecycle step, but a read-only reviewer never takes
 * an "everybody" (ROLE_USER) step there.
 */
final class PolicyApprovalRolesTest extends KernelTestCase
{
    #[Test]
    public function aPolicyIsApprovedByTheCisoOrTopManagement(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $security = static::getContainer()->get(Security::class);
        $id = bin2hex(random_bytes(4));
        $tenant = (new Tenant())->setName('Policy ' . $id)->setCode('policy_' . $id);
        $em->persist($tenant);
        $manager = $this->user($em, $tenant, 'mgr-' . $id, ['ROLE_MANAGER']);
        $ciso = $this->user($em, $tenant, 'ciso-' . $id, ['ROLE_CISO']);
        $board = $this->user($em, $tenant, 'board-' . $id, ['ROLE_TOP_MGMT']);

        $factory = new ContractRecordFactory($em);
        $policy = $factory->create(Document::class, $tenant, 'policy ' . $id);
        $policy->setPolicyBody('# Informationssicherheitsleitlinie');
        $procedure = $factory->create(Document::class, $tenant, 'procedure ' . $id);
        $procedure->setPolicyBody(null);
        $em->flush();

        foreach (['approve', 'publish'] as $step) {
            $attribute = 'lifecycle.document_lifecycle.' . $step;
            self::assertFalse($security->isGrantedForUser($manager, $attribute, $policy), $step . ': a manager decided on a policy');
            self::assertTrue($security->isGrantedForUser($ciso, $attribute, $policy), $step . ': the CISO may not');
            self::assertTrue($security->isGrantedForUser($board, $attribute, $policy), $step . ': top management may not');
            self::assertTrue($security->isGrantedForUser($manager, $attribute, $procedure), $step . ': other documents stay with the manager');
            self::assertFalse($security->isGrantedForUser($board, $attribute, $procedure), $step . ': top management is no manager');
        }

        // A read-only reviewer reaches ROLE_USER for reading only: no "everybody" step.
        self::assertFalse($security->isGrantedForUser($board, 'lifecycle.document_lifecycle.submit_for_review', $procedure));
        self::assertTrue($security->isGrantedForUser($manager, 'lifecycle.document_lifecycle.submit_for_review', $procedure));

        $subscriber = new ReadOnlyRoleSubscriber($security);
        self::assertFalse($subscriber->isRestricted('app_lifecycle_transition', 'POST'), 'top management cannot reach the step');
        self::assertTrue($subscriber->isRestricted('app_lifecycle_bulk_transition', 'POST'), 'bulk steps stay closed to reviewers');
    }

    /** @param list<string> $roles */
    private function user(EntityManagerInterface $em, Tenant $tenant, string $name, array $roles): User
    {
        $user = (new User())
            ->setEmail($name . '@example.test')
            ->setFirstName('Policy')
            ->setLastName($name)
            ->setRoles($roles)
            ->setPassword('hashed')
            ->setTenant($tenant)
            ->setIsActive(true);
        $em->persist($user);

        return $user;
    }
}
