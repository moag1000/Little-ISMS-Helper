<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\AuditLog;
use App\Entity\AuditProgram;
use App\Entity\Tenant;
use App\Entity\User;
use App\Service\ModuleConfigurationService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Csrf\TokenGenerator\UriSafeTokenGenerator;

/**
 * Archiving an audit programme goes through audit_program_lifecycle: the
 * `archive` transition needs a reason and writes a status_change audit row;
 * it must never be a bare setStatus('archived').
 */
final class AuditProgramArchiveLifecycleTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Tenant $tenant;
    private User $manager;
    private AuditProgram $program;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);

        $lock = $container->getParameter('kernel.project_dir') . '/config/setup_complete.lock';
        if (!file_exists($lock)) {
            @file_put_contents($lock, date('c'));
        }

        $modules = $this->createMock(ModuleConfigurationService::class);
        $modules->method('isModuleActive')->willReturn(true);
        $container->set(ModuleConfigurationService::class, $modules);

        $uid = uniqid('ap_lc_', true);
        $this->tenant = (new Tenant())->setName('Tenant ' . $uid)->setCode('aplc_' . substr($uid, -7));
        $this->em->persist($this->tenant);

        $this->manager = new User();
        $this->manager->setEmail('mgr_' . $uid . '@x.test')->setFirstName('M')->setLastName('G')
            ->setRoles(['ROLE_MANAGER'])->setPassword('hashed')->setTenant($this->tenant)->setIsActive(true);
        $this->em->persist($this->manager);

        $this->program = new AuditProgram();
        $this->program->setTenant($this->tenant);
        $this->program->setName('Programme ' . $uid);
        $this->program->setStartDate(new DateTimeImmutable('2026-01-01'));
        $this->program->setEndDate(new DateTimeImmutable('2026-12-31'));
        $this->em->persist($this->program);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        try {
            foreach ([AuditProgram::class => $this->program, User::class => $this->manager, Tenant::class => $this->tenant] as $class => $e) {
                $row = $this->em->find($class, $e->getId());
                if ($row !== null) {
                    $this->em->remove($row);
                }
            }
            $this->em->flush();
        } catch (\Throwable) {
        }
        parent::tearDown();
    }

    #[Test]
    public function archiveWithReasonTransitionsAndWritesStatusChangeAuditRow(): void
    {
        $this->client->loginUser($this->manager);
        $token = $this->csrfToken('archive-audit-program-' . $this->program->getId());

        $this->client->request('POST', '/en/audit-programs/' . $this->program->getId() . '/archive', [
            '_token' => $token,
            'reason' => 'Programme superseded by 2027 cycle',
        ]);

        self::assertResponseRedirects('/en/audit-programs');
        $this->em->clear();
        $program = $this->em->find(AuditProgram::class, $this->program->getId());
        self::assertSame('archived', $program->getStatus());
        self::assertNotNull($program->getArchivedAt());

        $log = $this->em->getRepository(AuditLog::class)->findOneBy([
            'entityType' => 'AuditProgram',
            'entityId' => $this->program->getId(),
            'action' => 'status_change',
        ]);
        self::assertNotNull($log, 'lifecycle AuditLogListener must record the archive transition');
    }

    #[Test]
    public function archiveWithoutReasonIsRejectedAndKeepsStatus(): void
    {
        $this->client->loginUser($this->manager);
        $token = $this->csrfToken('archive-audit-program-' . $this->program->getId());

        $this->client->request('POST', '/en/audit-programs/' . $this->program->getId() . '/archive', [
            '_token' => $token,
            'reason' => '   ',
        ]);

        self::assertResponseRedirects('/en/audit-programs/' . $this->program->getId());
        $this->em->clear();
        $program = $this->em->find(AuditProgram::class, $this->program->getId());
        self::assertSame('planning', $program->getStatus());
        self::assertNull($program->getArchivedAt());
    }

    private function csrfToken(string $id): string
    {
        $this->client->request('GET', '/');
        $session = $this->client->getRequest()->getSession();
        $value = (new UriSafeTokenGenerator())->generateToken();
        $session->set('_csrf/' . $id, $value);
        $session->save();

        return $value;
    }
}
