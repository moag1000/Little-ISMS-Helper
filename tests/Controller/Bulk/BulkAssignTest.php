<?php

declare(strict_types=1);

namespace App\Tests\Controller\Bulk;

use App\Entity\Risk;
use App\Entity\Tenant;
use App\Entity\User;
use App\Enum\RiskStatus;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Csrf\TokenGenerator\UriSafeTokenGenerator;

/**
 * End-to-end bulk "assign": the picker list (GET /bulk/assignees) is
 * tenant-scoped and manager-only, the POST writes the owner, audit-logs the
 * batch and refuses an inactive assignee.
 */
final class BulkAssignTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    /** @var list<object> */
    private array $created = [];
    private Tenant $tenant;
    private User $manager;
    private User $member;
    private User $inactive;
    private User $foreigner;
    private Risk $risk;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $uid = substr(uniqid('', true), -8);
        $this->tenant = $this->keep((new Tenant())->setName('Assign ' . $uid)->setCode('as_' . $uid));
        $other = $this->keep((new Tenant())->setName('Other ' . $uid)->setCode('ao_' . $uid));

        $this->manager = $this->user('mgr_' . $uid, ['ROLE_MANAGER'], $this->tenant, 'Mia', 'Manager');
        $this->member = $this->user('mem_' . $uid, ['ROLE_USER'], $this->tenant, 'Max', 'Member');
        $this->inactive = $this->user('off_' . $uid, ['ROLE_USER'], $this->tenant, 'Ina', 'Inactive', false);
        $this->foreigner = $this->user('frn_' . $uid, ['ROLE_USER'], $other, 'Fred', 'Foreign');

        $this->risk = $this->keep((new Risk())->setTitle('Assign risk ' . $uid)->setCategory('security')
            ->setProbability(2)->setImpact(2)->setDescription('d')->setStatus(RiskStatus::Identified)
            ->setTenant($this->tenant));

        $this->em->flush();
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->created) as $entity) {
            try {
                $managed = $this->em->find($entity::class, $entity->getId());
                if ($managed !== null) {
                    $this->em->remove($managed);
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
    public function assigneeListIsTenantScopedAndActiveOnly(): void
    {
        $this->client->loginUser($this->manager);
        $this->client->request('GET', '/en/bulk/assignees');

        self::assertResponseIsSuccessful();
        $ids = array_column(json_decode((string) $this->client->getResponse()->getContent(), true)['assignees'], 'id');
        self::assertContains($this->member->getId(), $ids);
        self::assertContains($this->manager->getId(), $ids);
        self::assertNotContains($this->inactive->getId(), $ids);
        self::assertNotContains($this->foreigner->getId(), $ids);
    }

    #[Test]
    public function assigneeListRequiresManager(): void
    {
        $this->client->loginUser($this->member);
        $this->client->request('GET', '/en/bulk/assignees');

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    #[Test]
    public function assignWritesOwnerAndAuditLogsTheBatch(): void
    {
        $this->client->loginUser($this->manager);
        $this->post(['ids' => [$this->risk->getId()], 'assignee_id' => $this->member->getId()]);

        self::assertResponseIsSuccessful();
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame(['ok' => true, 'changed' => 1, 'rejected' => []], $body);

        $this->em->clear();
        $risk = $this->em->find(Risk::class, $this->risk->getId());
        self::assertSame($this->member->getId(), $risk?->getRiskOwner()?->getId());

        $rowEntries = (int) $this->em->getConnection()->fetchOne(
            "SELECT COUNT(*) FROM audit_log WHERE entity_type = 'Risk' AND entity_id = ? AND new_values LIKE '%_batch_id%'",
            [$this->risk->getId()],
        );
        self::assertSame(1, $rowEntries, 'one per-entity entry of the logBulk batch');
    }

    #[Test]
    public function inactiveAssigneeIsRefused(): void
    {
        $this->client->loginUser($this->manager);
        $this->post(['ids' => [$this->risk->getId()], 'assignee_id' => $this->inactive->getId()]);

        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('assignee_inactive', $body['error'] ?? null);
        self::assertSame(0, $body['changed'] ?? -1);
    }

    #[Test]
    public function foreignTenantAssigneeIsRefused(): void
    {
        $this->client->loginUser($this->manager);
        $this->post(['ids' => [$this->risk->getId()], 'assignee_id' => $this->foreigner->getId()]);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    /** @param array<string, mixed> $payload */
    private function post(array $payload): void
    {
        $this->client->request('GET', '/');
        $session = $this->client->getRequest()->getSession();
        $token = (new UriSafeTokenGenerator())->generateToken();
        $session->set('_csrf/bulk_action', $token);
        $session->save();

        $this->client->request('POST', '/en/risk/bulk-assign', [], [], ['CONTENT_TYPE' => 'application/json'],
            (string) json_encode($payload + ['_token' => $token]));
    }

    /** @param list<string> $roles */
    private function user(string $local, array $roles, Tenant $tenant, string $first, string $last, bool $active = true): User
    {
        return $this->keep((new User())->setEmail($local . '@x.test')->setFirstName($first)->setLastName($last)
            ->setRoles($roles)->setPassword('hashed')->setTenant($tenant)->setIsActive($active));
    }

    /**
     * @template T of object
     * @param T $entity
     * @return T
     */
    private function keep(object $entity): object
    {
        $this->em->persist($entity);
        $this->created[] = $entity;

        return $entity;
    }
}
