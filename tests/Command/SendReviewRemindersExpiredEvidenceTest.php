<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceRequirement;
use App\Entity\ComplianceRequirementFulfillment;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\ComplianceRequirementFulfillmentRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Integration test proving the daily review-reminder command wires the
 * generic evidence-expiry re-review mechanism
 * ({@see \App\Service\Evidence\EvidenceCascadeInvalidationService::flagExpiredEvidence()})
 * for every active tenant.
 *
 * This is the production wiring that makes certificate-expiry re-review
 * actually fire: certificate-applied fulfillments get
 * nextReviewDate = cert.validUntil, and this daily command is the caller that
 * flags them outdated once that date passes.
 *
 * Requires a real database (APP_ENV=test). Each test runs in a transaction
 * rolled back in tearDown().
 */
#[Group('integration')]
class SendReviewRemindersExpiredEvidenceTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->em->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->em->getConnection()->isTransactionActive()) {
            $this->em->rollback();
        }
        parent::tearDown();
    }

    #[Test]
    public function commandFlagsExpiredEvidenceForActiveTenant(): void
    {
        [$tenant, $fulfillment] = $this->seedOverdueFulfillment();
        $fulfillmentId = $fulfillment->getId();

        $tester = $this->commandTester();
        $exitCode = $tester->execute([]);

        self::assertSame(0, $exitCode, $tester->getDisplay());

        $this->em->clear();
        $reloaded = $this->repo()->find($fulfillmentId);
        self::assertNotNull($reloaded);
        self::assertTrue(
            $reloaded->isEvidenceOutdated(),
            'The daily command must flag the overdue fulfillment as evidenceOutdated.',
        );

        self::assertStringContainsString('Evidence Expiry', $tester->getDisplay());
    }

    #[Test]
    public function dryRunDoesNotFlagExpiredEvidence(): void
    {
        [, $fulfillment] = $this->seedOverdueFulfillment();
        $fulfillmentId = $fulfillment->getId();

        $tester = $this->commandTester();
        $exitCode = $tester->execute(['--dry-run' => true]);

        self::assertSame(0, $exitCode, $tester->getDisplay());

        $this->em->clear();
        $reloaded = $this->repo()->find($fulfillmentId);
        self::assertNotNull($reloaded);
        self::assertFalse(
            $reloaded->isEvidenceOutdated(),
            'Dry-run must NOT mutate the evidenceOutdated flag.',
        );
    }

    private function commandTester(): CommandTester
    {
        $application = new Application(self::$kernel);
        $command = $application->find('app:review:send-reminders');

        return new CommandTester($command);
    }

    /**
     * @return array{0: Tenant, 1: ComplianceRequirementFulfillment}
     */
    private function seedOverdueFulfillment(): array
    {
        $tenant = new Tenant();
        $tenant->setName('Reminder Cmd Tenant');
        $tenant->setCode('remcmd_' . uniqid());
        $tenant->setIsActive(true);
        $this->em->persist($tenant);

        $framework = new ComplianceFramework();
        $framework->setCode('REMFW_' . uniqid())
            ->setName('Reminder Test Framework')
            ->setDescription('Integration-test framework')
            ->setVersion('1.0')
            ->setApplicableIndustry('all')
            ->setRegulatoryBody('Test')
            ->setMandatory(false)
            ->setActive(true);
        $this->em->persist($framework);

        $req = new ComplianceRequirement();
        $req->setFramework($framework)
            ->setRequirementId('R1')
            ->setTitle('Req R1')
            ->setDescription('Test requirement')
            ->setPriority('medium');
        $framework->addRequirement($req);
        $this->em->persist($req);

        $fulfillment = new ComplianceRequirementFulfillment();
        $fulfillment->setTenant($tenant);
        $fulfillment->setRequirement($req);
        $fulfillment->setNextReviewDate(new DateTimeImmutable('-1 day'));
        $fulfillment->setEvidenceOutdated(false);
        $this->em->persist($fulfillment);

        $this->em->flush();

        return [$tenant, $fulfillment];
    }

    private function repo(): ComplianceRequirementFulfillmentRepository
    {
        return self::getContainer()->get(ComplianceRequirementFulfillmentRepository::class);
    }
}
