<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\AuditLog;
use App\Entity\Tenant;
use App\Entity\User;
use App\Form\TenantType;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Csrf\TokenGenerator\UriSafeTokenGenerator;

/**
 * Suspending / reactivating an organisation goes through tenant_lifecycle:
 * SUPER_ADMIN only, reason required, four-eyes approver, status_change audit
 * row. The edit form no longer carries an isActive checkbox that would flip
 * the status around the lifecycle.
 */
final class TenantStatusLifecycleTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    /** @var list<object> */
    private array $created = [];
    private Tenant $home;
    private Tenant $target;
    private User $superAdmin;
    private User $secondSuperAdmin;
    private User $admin;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $uid = substr(uniqid('', true), -10);
        $this->home = (new Tenant())->setName('Home ' . $uid)->setCode('tsl_h_' . $uid);
        $this->home->setStatus(Tenant::STATUS_ACTIVE); // @phpstan-ignore lifecycle.directSetStatus (test fixture)
        $this->persist($this->home);
        $this->target = (new Tenant())->setName('Target ' . $uid)->setCode('tsl_t_' . $uid);
        $this->target->setStatus(Tenant::STATUS_ACTIVE); // @phpstan-ignore lifecycle.directSetStatus (test fixture)
        $this->persist($this->target);

        $this->superAdmin = $this->user('sa1', $uid, ['ROLE_SUPER_ADMIN', 'ROLE_ADMIN']);
        $this->secondSuperAdmin = $this->user('sa2', $uid, ['ROLE_SUPER_ADMIN', 'ROLE_ADMIN']);
        $this->admin = $this->user('adm', $uid, ['ROLE_ADMIN']);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->created) as $entity) {
            try {
                $row = $this->em->find($entity::class, $entity->getId());
                if ($row !== null) {
                    $this->em->remove($row);
                }
            } catch (\Throwable) {
            }
        }
        try {
            $this->em->flush();
        } catch (\Throwable) {
        }
        parent::tearDown();
    }

    #[Test]
    public function suspendWithReasonAndSecondApproverSuspendsAndAudits(): void
    {
        $this->toggle($this->superAdmin, 'Billing dispute, ticket 4711', $this->secondSuperAdmin);

        $tenant = $this->reload();
        self::assertSame(Tenant::STATUS_SUSPENDED, $tenant->getStatus());
        self::assertFalse($tenant->isActive());
        self::assertNotNull($this->em->getRepository(AuditLog::class)->findOneBy([
            'entityType' => 'Tenant',
            'entityId' => $this->target->getId(),
            'action' => 'status_change',
        ]), 'AuditLogListener must record the suspend transition');
    }

    #[Test]
    public function reactivateNeedsTheSameControls(): void
    {
        $this->toggle($this->superAdmin, 'Billing dispute', $this->secondSuperAdmin);
        $this->toggle($this->secondSuperAdmin, 'Dispute settled', $this->superAdmin);

        $tenant = $this->reload();
        self::assertSame(Tenant::STATUS_ACTIVE, $tenant->getStatus());
        self::assertTrue($tenant->isActive());
    }

    #[Test]
    public function suspendWithoutReasonIsRejected(): void
    {
        $this->toggle($this->superAdmin, '', $this->secondSuperAdmin);

        self::assertSame(Tenant::STATUS_ACTIVE, $this->reload()->getStatus());
    }

    #[Test]
    public function suspendWithoutSecondApproverIsRejectedWhenOneExists(): void
    {
        $this->toggle($this->superAdmin, 'Security incident', null);
        self::assertSame(Tenant::STATUS_ACTIVE, $this->reload()->getStatus());

        $this->toggle($this->superAdmin, 'Security incident', $this->superAdmin);
        self::assertSame(Tenant::STATUS_ACTIVE, $this->reload()->getStatus(), 'self-approval is not four-eyes');
    }

    #[Test]
    public function plainAdminCannotSuspend(): void
    {
        $this->toggle($this->admin, 'Not my call', $this->superAdmin);

        self::assertSame(Tenant::STATUS_ACTIVE, $this->reload()->getStatus());
    }

    #[Test]
    public function tenantFormHasNoStatusCheckbox(): void
    {
        $form = static::getContainer()->get('form.factory')->create(TenantType::class, new Tenant());

        self::assertFalse($form->has('isActive'));
        self::assertNotContains('isActive', array_merge(...array_values(TenantType::getSectionMap())));
    }

    private function toggle(User $as, string $reason, ?User $approver): void
    {
        $this->client->loginUser($as);
        $this->client->request('GET', '/');
        $session = $this->client->getRequest()->getSession();
        $token = (new UriSafeTokenGenerator())->generateToken();
        $session->set('_csrf/toggle_tenant_' . $this->target->getId(), $token);
        $session->save();

        $this->client->request('POST', '/en/admin/tenants/' . $this->target->getId() . '/toggle', [
            '_token' => $token,
            'reason' => $reason,
            'four_eyes_approver_id' => $approver?->getId() ?? '',
        ]);
        self::assertResponseRedirects('/en/admin/tenants/' . $this->target->getId());
    }

    private function reload(): Tenant
    {
        $this->em->clear();

        return $this->em->find(Tenant::class, $this->target->getId());
    }

    /** @param list<string> $roles */
    private function user(string $prefix, string $uid, array $roles): User
    {
        $user = new User();
        $user->setEmail($prefix . '_' . $uid . '@tsl.test')->setFirstName('T')->setLastName($prefix)
            ->setRoles($roles)->setPassword('hashed')->setTenant($this->home)->setIsActive(true);
        $this->persist($user);

        return $user;
    }

    private function persist(object $entity): void
    {
        $this->em->persist($entity);
        $this->created[] = $entity;
    }
}
