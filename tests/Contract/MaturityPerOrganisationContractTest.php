<?php

declare(strict_types=1);

namespace App\Tests\Contract;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceRequirement;
use App\Entity\Tenant;
use App\Service\Compliance\RequirementMaturity;
use App\Service\IndustryBaselineService;
use App\Service\MrisMaturityService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * S18 — a Reifegrad is the organisation's own assessment. Writing it in organisation A (MRIS
 * Ist/Soll, industry baseline Soll) never changes what organisation B reads, and never lands on
 * the global catalogue requirement both share.
 */
final class MaturityPerOrganisationContractTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Tenant $a;
    private Tenant $b;
    /** @var list<object> */
    private array $created = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $id = bin2hex(random_bytes(5));
        $this->a = $this->keep((new Tenant())->setName('Maturity A ' . $id)->setCode('mta_' . $id));
        $this->b = $this->keep((new Tenant())->setName('Maturity B ' . $id)->setCode('mtb_' . $id));
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
    public function mrisMaturityOfOrganisationADoesNotReachOrganisationB(): void
    {
        $requirement = $this->globalRequirement($this->framework('ZZ-S18-' . bin2hex(random_bytes(3))), 'MHC-01');
        $service = static::getContainer()->get(MrisMaturityService::class);

        $service->setCurrent($requirement, $this->a, 'managed');
        $service->setTarget($requirement, $this->a, 'managed');
        $service->setCurrent($requirement, $this->b, 'initial');
        $this->em->clear();

        $requirement = $this->em->find(ComplianceRequirement::class, $requirement->getId());
        $a = $this->em->find(Tenant::class, $this->a->getId());
        $b = $this->em->find(Tenant::class, $this->b->getId());
        $maturity = static::getContainer()->get(RequirementMaturity::class);
        self::assertNull($requirement->getMaturityCurrent(), 'the shared catalogue row carries no organisation value');
        self::assertNull($requirement->getMaturityTarget());
        self::assertSame('managed', $maturity->current($requirement, $a));
        self::assertSame('managed', $maturity->target($requirement, $a));
        self::assertSame('initial', $maturity->current($requirement, $b));
        self::assertNull($maturity->target($requirement, $b), "A's Soll is not B's");
    }

    #[Test]
    public function industryBaselineOfOrganisationADoesNotReachOrganisationB(): void
    {
        $framework = $this->em->getRepository(ComplianceFramework::class)->findOneBy(['code' => 'ISO27001'])
            ?? $this->framework('ISO27001');
        $requirement = $this->em->getRepository(ComplianceRequirement::class)
            ->findOneBy(['framework' => $framework, 'requirementId' => 'A.5.1', 'uploadTenant' => null])
            ?? $this->globalRequirement($framework, 'A.5.1');
        $catalogueDefault = $requirement->getMaturityTarget();

        static::getContainer()->get(IndustryBaselineService::class)
            ->applyBaseline($this->a, 'ISO27001', 'iso27001-finance');
        $this->em->clear();

        $requirement = $this->em->find(ComplianceRequirement::class, $requirement->getId());
        $maturity = static::getContainer()->get(RequirementMaturity::class);
        self::assertSame($catalogueDefault, $requirement->getMaturityTarget(), 'the shared catalogue row is untouched');
        self::assertSame('5', $maturity->target($requirement, $this->em->find(Tenant::class, $this->a->getId())));
        self::assertSame($catalogueDefault, $maturity->target($requirement, $this->em->find(Tenant::class, $this->b->getId())));
    }

    private function framework(string $code): ComplianceFramework
    {
        return $this->keep((new ComplianceFramework())
            ->setCode($code)
            ->setName('S18 ' . $code)
            ->setVersion('1')
            ->setApplicableIndustry('all')
            ->setRegulatoryBody('test'));
    }

    private function globalRequirement(ComplianceFramework $framework, string $requirementId): ComplianceRequirement
    {
        $requirement = $this->keep((new ComplianceRequirement())
            ->setFramework($framework)
            ->setRequirementId($requirementId)
            ->setTitle('S18 ' . $requirementId)
            ->setDescription('S18 contract')
            ->setPriority('medium'));
        $this->em->flush();

        return $requirement;
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
