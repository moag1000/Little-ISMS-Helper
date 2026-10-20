<?php

declare(strict_types=1);

namespace App\Tests\Service\ComplianceWizard\Check\PolicyWizard;

use App\Entity\Tenant;
use App\Service\ComplianceWizard\Check\PolicyWizard\PolicyWizardCheckRegistry;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Runs every registered policy-wizard check against the real database.
 *
 * The per-check unit tests mock the query builders, so DQL the ORM cannot
 * parse (e.g. MySQL-only functions like GREATEST()) slipped through and
 * crashed the dashboard with HTTP 500. This test executes the real queries.
 */
#[Group('integration')]
final class PolicyWizardChecksRunAgainstDatabaseTest extends KernelTestCase
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
    public function everyCheckRunsWithoutQueryErrors(): void
    {
        $tenant = new Tenant();
        $tenant->setName('Policy Wizard DQL Tenant');
        $tenant->setCode('pwdql_' . uniqid());
        $this->em->persist($tenant);
        $this->em->flush();

        $checks = self::getContainer()->get(PolicyWizardCheckRegistry::class)->all();
        self::assertNotEmpty($checks);

        foreach ($checks as $check) {
            $result = $check->run($tenant);
            self::assertSame($check->getCheckId(), $result->checkId, $check::class);
        }
    }
}
