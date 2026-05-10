<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\SoaSnapshot;
use App\Entity\Tenant;
use App\Entity\User;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

class SoaSnapshotControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private ?Tenant $tenant = null;
    private ?User $userBasic = null;
    private ?User $auditor = null;
    private ?SoaSnapshot $snapshot = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->createFixtures();
    }

    protected function tearDown(): void
    {
        try {
            if ($this->snapshot) {
                $managed = $this->entityManager->find(SoaSnapshot::class, $this->snapshot->getId());
                if ($managed) {
                    $this->entityManager->remove($managed);
                }
            }
            foreach ([$this->userBasic, $this->auditor] as $u) {
                if ($u === null) { continue; }
                $managed = $this->entityManager->find(User::class, $u->getId());
                if ($managed) {
                    $this->entityManager->remove($managed);
                }
            }
            if ($this->tenant) {
                $managed = $this->entityManager->find(Tenant::class, $this->tenant->getId());
                if ($managed) {
                    $this->entityManager->remove($managed);
                }
            }
            $this->entityManager->flush();
        } catch (\Throwable) {
            // best-effort cleanup
        }

        parent::tearDown();
    }

    private function createFixtures(): void
    {
        $uniq = uniqid('soa_snap_', true);

        $this->tenant = new Tenant();
        $this->tenant->setName('Tenant ' . $uniq);
        $this->tenant->setCode('t_' . substr(md5($uniq), 0, 16));
        $this->entityManager->persist($this->tenant);

        $this->userBasic = (new User())
            ->setEmail('basic_' . $uniq . '@example.com')
            ->setFirstName('Basic')->setLastName('User')
            ->setRoles(['ROLE_USER'])
            ->setPassword('hashed')
            ->setTenant($this->tenant)
            ->setIsActive(true);
        $this->entityManager->persist($this->userBasic);

        $this->auditor = (new User())
            ->setEmail('auditor_' . $uniq . '@example.com')
            ->setFirstName('Audit')->setLastName('Or')
            ->setRoles(['ROLE_AUDITOR'])
            ->setPassword('hashed')
            ->setTenant($this->tenant)
            ->setIsActive(true);
        $this->entityManager->persist($this->auditor);

        $this->snapshot = new SoaSnapshot();
        $this->snapshot->setTenant($this->tenant);
        $this->snapshot->setCreatedBy($this->auditor);
        $this->snapshot->setAsOfDate(new DateTimeImmutable('2026-04-01'));
        $this->snapshot->setPurpose('TEST: Pre-cert dry-run');
        $this->snapshot->setPayload([
            'tenant_id' => null,
            'tenant_name' => $this->tenant->getName(),
            'as_of_date' => '2026-04-01',
            'snapshot_engine_version' => '1',
            'control_count' => 0,
            'controls' => [],
        ]);
        $this->snapshot->setChecksumSha256(str_repeat('a', 64));
        $this->entityManager->persist($this->snapshot);

        $this->entityManager->flush();
    }

    #[Test]
    public function indexRequiresAuditorRole(): void
    {
        $this->client->loginUser($this->userBasic);
        $this->client->request('GET', '/en/soa/snapshot');
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    #[Test]
    public function indexRendersForAuditor(): void
    {
        $this->client->loginUser($this->auditor);
        $this->client->request('GET', '/en/soa/snapshot');
        $this->assertResponseIsSuccessful();
        self::assertStringContainsString('TEST: Pre-cert dry-run', $this->client->getResponse()->getContent() ?: '');
    }

    #[Test]
    public function newRendersFormForAuditor(): void
    {
        $this->client->loginUser($this->auditor);
        $this->client->request('GET', '/en/soa/snapshot/new');
        $this->assertResponseIsSuccessful();
        self::assertStringContainsString('as_of_date', $this->client->getResponse()->getContent() ?: '');
    }

    #[Test]
    public function showRendersForOwningTenant(): void
    {
        $this->client->loginUser($this->auditor);
        $this->client->request('GET', '/en/soa/snapshot/' . $this->snapshot->getId());
        $this->assertResponseIsSuccessful();
        self::assertStringContainsString(str_repeat('a', 16), $this->client->getResponse()->getContent() ?: '');
    }
}
