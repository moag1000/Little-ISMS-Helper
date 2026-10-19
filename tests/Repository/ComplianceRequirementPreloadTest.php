<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceRequirement;
use App\Entity\Control;
use App\Repository\ComplianceRequirementRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\PersistentCollection;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * getCompliancePercentage() derives each requirement's status from its mapped
 * controls. Without preloading that is one lazy-load per requirement (N+1);
 * preloadMappedControls() must bound it to a constant number of queries.
 */
#[Group('integration')]
final class ComplianceRequirementPreloadTest extends KernelTestCase
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
        $this->em->rollback();
        parent::tearDown();
    }

    #[Test]
    public function percentageIsIdenticalAndQueryCountIsBoundedAfterPreload(): void
    {
        $framework = (new ComplianceFramework())
            ->setCode('PRELOAD-' . uniqid())->setName('Preload test')->setVersion('1')
            ->setApplicableIndustry('all')->setRegulatoryBody('test')->setRequiredModules([])
            ->setScopeDescription('t')->setMandatory(false)->setActive(true);
        $this->em->persist($framework);

        $n = 12;
        for ($i = 0; $i < $n; $i++) {
            $req = (new ComplianceRequirement())
                ->setFramework($framework)->setRequirementId('R-' . $i)->setTitle('t')
                ->setDescription('d')->setCategory('c')->setPriority('high')->setRequirementType('core');
            $control = (new Control())->setControlId('PL-' . $i . uniqid())->setName('c')
                ->setDescription('d')->setCategory('c')->setApplicable(true)->setImplementationStatus($i < 6 ? 'implemented' : 'planned')->setImplementationPercentage($i < 6 ? 100 : 0);
            $this->em->persist($control);
            $req->addMappedControl($control);
            $this->em->persist($req);
        }
        // Parity cases: top-level without mapped controls (not implemented) and a
        // child requirement (excluded from numerator and denominator).
        $bare = (new ComplianceRequirement())
            ->setFramework($framework)->setRequirementId('R-bare')->setTitle('t')
            ->setDescription('d')->setCategory('c')->setPriority('high')->setRequirementType('core');
        $this->em->persist($bare);
        $child = (new ComplianceRequirement())
            ->setFramework($framework)->setRequirementId('R-child')->setTitle('t')
            ->setDescription('d')->setCategory('c')->setPriority('high')->setRequirementType('sub_requirement')
            ->setParentRequirement($bare);
        $this->em->persist($child);
        $this->em->flush();
        $id = $framework->getId();
        $this->em->clear();

        $loaded = $this->em->find(ComplianceFramework::class, $id);
        $loaded->getRequirements()->count();

        /** @var ComplianceRequirementRepository $repo */
        $repo = self::getContainer()->get(ComplianceRequirementRepository::class);
        $before = $this->questions();
        $repo->preloadMappedControls($loaded);
        $percentage = $loaded->getCompliancePercentage();
        $used = $this->questions() - $before - 1; // minus the SHOW STATUS probe itself

        // 6 of 13 top-level fulfilled (12 mapped + 1 bare); the child is ignored.
        $this->assertSame(46.2, $percentage);
        $this->assertLessThanOrEqual(3, $used, "expected bounded queries, got $used for $n requirements");
        foreach ($loaded->getRequirements() as $r) {
            if ($r->getParentRequirement() === null) {
                $controls = $r->getMappedControls();
                $this->assertInstanceOf(PersistentCollection::class, $controls);
                $this->assertTrue($controls->isInitialized());
            }
        }

        // Parity with the un-preloaded (lazy) computation.
        $this->assertSame($percentage, $this->freshPercentage($id));
    }

    private function freshPercentage(int $id): float
    {
        $this->em->clear();

        return $this->em->find(ComplianceFramework::class, $id)->getCompliancePercentage();
    }

    private function questions(): int
    {
        $row = $this->em->getConnection()->fetchAssociative("SHOW SESSION STATUS LIKE 'Questions'");

        return (int) $row['Value'];
    }
}
