<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceRequirement;
use App\Entity\ComplianceRequirementFulfillment;
use App\Entity\Tenant;
use App\Repository\ComplianceRequirementFulfillmentRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The framework list's "Erfüllung" counts the viewer's organisation only:
 * another organisation's 100 % never shows up, sub-requirements and
 * not-applicable rows do not count.
 */
#[Group('integration')]
final class FulfilledShareByFrameworkTest extends KernelTestCase
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
    public function countsOnlyTheOrganisationsOwnFulfilledTopLevelRequirements(): void
    {
        $uid = uniqid();
        $own = (new Tenant())->setName('Own ' . $uid)->setCode('own_' . $uid);
        $other = (new Tenant())->setName('Other ' . $uid)->setCode('oth_' . $uid);
        $this->em->persist($own);
        $this->em->persist($other);

        $framework = (new ComplianceFramework())
            ->setCode('SHARE-' . $uid)->setName('Share test')->setVersion('1')
            ->setApplicableIndustry('all')->setRegulatoryBody('test')->setRequiredModules([])
            ->setScopeDescription('t')->setMandatory(false)->setActive(true);
        $this->em->persist($framework);

        $reqs = [];
        for ($i = 0; $i < 4; $i++) {
            $reqs[$i] = $this->requirement($framework, 'S-' . $i, 'core');
        }
        $child = $this->requirement($framework, 'S-child', 'sub_requirement')->setParentRequirement($reqs[0]);

        $this->fulfil($own, $reqs[0], 100);
        $this->fulfil($own, $reqs[1], 60);
        $this->fulfil($own, $reqs[2], 100, applicable: false);
        $this->fulfil($own, $child, 100);
        foreach ($reqs as $req) {
            $this->fulfil($other, $req, 100);
        }
        $this->em->flush();

        /** @var ComplianceRequirementFulfillmentRepository $repo */
        $repo = self::getContainer()->get(ComplianceRequirementFulfillmentRepository::class);
        $fwId = (int) $framework->getId();

        self::assertSame([$fwId => 25.0], $repo->fulfilledShareByFramework($own, [$fwId]));
        self::assertSame([$fwId => 100.0], $repo->fulfilledShareByFramework($other, [$fwId]));
        self::assertSame([], $repo->fulfilledShareByFramework($own, []));
    }

    private function requirement(ComplianceFramework $framework, string $id, string $type): ComplianceRequirement
    {
        $req = (new ComplianceRequirement())
            ->setFramework($framework)->setRequirementId($id)->setTitle('t')
            ->setDescription('d')->setCategory('c')->setPriority('high')->setRequirementType($type);
        $this->em->persist($req);

        return $req;
    }

    private function fulfil(Tenant $tenant, ComplianceRequirement $req, int $pct, bool $applicable = true): void
    {
        $f = (new ComplianceRequirementFulfillment())
            ->setTenant($tenant)->setRequirement($req)->setFulfillmentPercentage($pct)->setApplicable($applicable);
        $this->em->persist($f);
    }
}
