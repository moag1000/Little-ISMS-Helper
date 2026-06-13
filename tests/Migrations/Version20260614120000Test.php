<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceRequirement;
use App\Entity\ComplianceRequirementFulfillment;
use App\Entity\Tenant;
use App\Enum\ComplianceRequirementFulfillmentStatus;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20260614120000;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Proves the framework-code consolidation migration preserves tenant fulfillment.
 * Acceptance criterion §8.6 of the catalog-remediation spec.
 *
 * Requires a real DB (APP_ENV=test). Each test runs in a rolled-back transaction.
 */
#[Group('integration')]
final class Version20260614120000Test extends KernelTestCase
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
    public function aliasFrameworkIsConsolidatedAndFulfillmentSurvives(): void
    {
        $tenant = new Tenant();
        $tenant->setName('Migration Test Tenant');
        $tenant->setCode('mig_' . uniqid());
        $this->em->persist($tenant);

        $alias = new ComplianceFramework();
        $alias->setCode('ISO22301');           // alias spelling
        $alias->setName('ISO 22301 (alias)');
        $alias->setVersion('2019');
        $alias->setApplicableIndustry('all_sectors');
        $alias->setRegulatoryBody('ISO');
        $alias->setMandatory(false);
        $this->em->persist($alias);

        $req = new ComplianceRequirement();
        $req->setFramework($alias);
        $req->setRequirementId('MIGTEST-' . uniqid());
        $req->setTitle('Continuity requirement');
        $req->setDescription('seed');
        $req->setPriority('medium');
        $req->setRequirementType('core');
        $this->em->persist($req);

        $ff = new ComplianceRequirementFulfillment();
        $ff->setTenant($tenant);
        $ff->setRequirement($req);
        $ff->setApplicable(true);
        $ff->setFulfillmentPercentage(50);
        $ff->setStatus(ComplianceRequirementFulfillmentStatus::InProgress);
        $this->em->persist($ff);

        $this->em->flush();

        $reqId = $req->getId();
        $ffId = $ff->getId();
        self::assertNotNull($reqId);
        self::assertNotNull($ffId);

        // Run the migration against the live (transactional) connection.
        // migrations/ is not PSR-4 autoloaded, so load the class explicitly.
        require_once \dirname(__DIR__, 2) . '/migrations/Version20260614120000.php';
        $migration = new Version20260614120000($this->em->getConnection(), new NullLogger());
        $migration->up(new Schema());

        // Reload everything fresh from the DB.
        $this->em->clear();

        // Alias framework gone; canonical present.
        $aliasStill = $this->em->getRepository(ComplianceFramework::class)->findOneBy(['code' => 'ISO22301']);
        self::assertNull($aliasStill, 'Alias code ISO22301 must be consolidated away');
        $canonical = $this->em->getRepository(ComplianceFramework::class)->findOneBy(['code' => 'ISO-22301']);
        self::assertInstanceOf(ComplianceFramework::class, $canonical, 'Canonical ISO-22301 must exist after merge');

        // Fulfillment still exists and still points at its requirement, now under canonical.
        $ffReloaded = $this->em->getRepository(ComplianceRequirementFulfillment::class)->find($ffId);
        self::assertInstanceOf(ComplianceRequirementFulfillment::class, $ffReloaded, 'Tenant fulfillment must survive the merge');
        self::assertSame($reqId, $ffReloaded->getRequirement()?->getId(), 'Fulfillment must still target the same requirement');
        self::assertSame(
            'ISO-22301',
            $ffReloaded->getRequirement()?->getFramework()?->getCode(),
            'The requirement behind the fulfillment must now live under the canonical framework',
        );
        self::assertSame(50, $ffReloaded->getFulfillmentPercentage(), 'Fulfillment data must be intact');
    }
}
