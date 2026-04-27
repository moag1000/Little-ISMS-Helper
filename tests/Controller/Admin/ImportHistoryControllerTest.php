<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\ImportRowEvent;
use App\Entity\ImportSession;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\ImportRowEventRepository;
use App\Service\Import\ImportSessionRecorder;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use PHPUnit\Framework\Attributes\Test;

/**
 * Integration tests for the ISB MINOR-1 per-row import-audit UI.
 *
 * Validates:
 *   - An ImportSession + row events survive the upload → commit flow
 *   - findByTarget('ComplianceMapping', ...) returns the recorded event
 *   - ROLE_USER gets 403 / ROLE_MANAGER gets 200 on the history route
 */
final class ImportHistoryControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private ?Tenant $tenant = null;
    private ?User $adminUser = null;
    private ?User $unprivilegedUser = null;
    private ?ImportSession $createdSession = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);
        $this->em = $em;

        $suffix = substr((string) bin2hex(random_bytes(4)), 0, 8);

        $this->tenant = (new Tenant())
            ->setCode('history-' . $suffix)
            ->setName('History Test Tenant ' . $suffix);
        $this->em->persist($this->tenant);

        // Per security.yaml, every /admin/* path is gated to ROLE_ADMIN by
        // the firewall access_control. ROLE_ADMIN inherits ROLE_MANAGER via
        // role_hierarchy, so the controller-level #[IsGranted('ROLE_MANAGER')]
        // is satisfied. We therefore use ROLE_ADMIN as the "privileged" user
        // for this integration test.
        $this->adminUser = (new User())
            ->setEmail('admin-hist-' . $suffix . '@example.test')
            ->setFirstName('Admin')
            ->setLastName('User')
            ->setRoles(['ROLE_ADMIN'])
            ->setPassword('hashed_password')
            ->setTenant($this->tenant)
            ->setAuthProvider('local')
            ->setIsActive(true);
        $this->em->persist($this->adminUser);

        $this->unprivilegedUser = (new User())
            ->setEmail('user-' . $suffix . '@example.test')
            ->setFirstName('Plain')
            ->setLastName('User')
            ->setRoles(['ROLE_USER'])
            ->setPassword('hashed_password')
            ->setTenant($this->tenant)
            ->setAuthProvider('local')
            ->setIsActive(true);
        $this->em->persist($this->unprivilegedUser);

        $this->em->flush();
    }

    protected function tearDown(): void
    {
        if (isset($this->em) && $this->em->isOpen()) {
            try {
                if ($this->createdSession) {
                    $reload = $this->em->find(ImportSession::class, $this->createdSession->getId());
                    if ($reload) {
                        $this->em->remove($reload);
                    }
                }
                foreach ([$this->adminUser, $this->unprivilegedUser] as $u) {
                    if ($u) {
                        $reload = $this->em->find(User::class, $u->getId());
                        if ($reload) {
                            $this->em->remove($reload);
                        }
                    }
                }
                if ($this->tenant) {
                    $reload = $this->em->find(Tenant::class, $this->tenant->getId());
                    if ($reload) {
                        $this->em->remove($reload);
                    }
                }
                $this->em->flush();
            } catch (\Throwable) {
                // best-effort cleanup
            }
        }

        parent::tearDown();
    }

    #[Test]
    public function testIndexRequiresAdmin(): void
    {
        // ROLE_USER is blocked by the firewall access_control on /admin/*.
        $this->client->loginUser($this->unprivilegedUser);
        $this->client->request('GET', '/en/admin/import/history');
        self::assertSame(Response::HTTP_FORBIDDEN, $this->client->getResponse()->getStatusCode());
    }

    #[Test]
    public function testIndexReturns200ForAdmin(): void
    {
        $this->client->loginUser($this->adminUser);
        $this->client->request('GET', '/en/admin/import/history');
        self::assertResponseIsSuccessful();
    }

    #[Test]
    public function testRecordedSessionAndRowEventAreRetrievable(): void
    {
        // Simulate the upload→commit flow by driving the recorder directly
        // (the full HTTP flow is covered by ComplianceImportController and
        // its CSRF / upload pipeline already).
        $container = static::getContainer();
        /** @var ImportSessionRecorder $recorder */
        $recorder = $container->get(ImportSessionRecorder::class);

        $fixtureDir = sys_get_temp_dir() . '/lih-history-test';
        if (!is_dir($fixtureDir)) {
            mkdir($fixtureDir, 0700, true);
        }
        $fixture = $fixtureDir . '/fixture-' . bin2hex(random_bytes(4)) . '.csv';
        file_put_contents($fixture, "source_framework\nISO27001\n");

        $session = $recorder->openSession(
            $fixture,
            ImportSession::FORMAT_CSV,
            'fixture.csv',
            $this->adminUser,
            $this->tenant,
        );
        $this->createdSession = $session;

        $mappingId = 987654;
        $recorder->recordRow(
            $session, 1, ImportRowEvent::DECISION_IMPORT,
            'ComplianceMapping', $mappingId,
            null,
            ['mapping_percentage' => 80, 'confidence' => 'high'],
            ['source_framework' => 'ISO27001'],
            null,
        );
        $recorder->closeSession($session, ImportSession::STATUS_COMMITTED);

        /** @var ImportRowEventRepository $repo */
        $repo = $this->em->getRepository(ImportRowEvent::class);
        $found = $repo->findByTarget('ComplianceMapping', $mappingId);
        self::assertCount(1, $found);
        self::assertSame(1, $found[0]->getLineNumber());
        self::assertSame(ImportRowEvent::DECISION_IMPORT, $found[0]->getDecision());
        self::assertNotNull($found[0]->getAfterState());
        self::assertStringContainsString('mapping_percentage', (string) $found[0]->getAfterState());

        // Session visible via the controller.
        $this->client->loginUser($this->adminUser);
        $this->client->request('GET', '/en/admin/import/history/' . $session->getId());
        self::assertResponseIsSuccessful();
        $html = $this->client->getResponse()->getContent();
        self::assertIsString($html);
        self::assertStringContainsString('fixture.csv', $html);
        self::assertStringContainsString(substr($session->getFileSha256(), 0, 12), $html);

        @unlink($fixture);
    }
}
