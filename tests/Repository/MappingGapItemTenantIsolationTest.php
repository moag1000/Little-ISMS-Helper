<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceMapping;
use App\Entity\ComplianceRequirement;
use App\Entity\MappingGapItem;
use App\Entity\Tenant;
use App\Repository\MappingGapItemRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Gap items inherit their owner from the mapping: gaps of a mapping that
 * involves an organisation's uploaded requirement belong to that
 * organisation, gaps of a global catalogue mapping stay shared. The tenant
 * filter shows an organisation its own plus the shared gaps — never another
 * organisation's.
 */
final class MappingGapItemTenantIsolationTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $filters = $this->em->getFilters();
        if (!$filters->isEnabled('tenant_filter')) {
            $filters->enable('tenant_filter');
        }
        $this->em->getConnection()->rollBack();
        parent::tearDown();
    }

    #[Test]
    public function ownerIsDerivedFromTheMapping(): void
    {
        $tenant = new Tenant();
        $global = (new ComplianceRequirement())->setUploadTenant(null);
        $uploaded = (new ComplianceRequirement())->setUploadTenant($tenant);
        $mapping = static fn(ComplianceRequirement $source, ComplianceRequirement $target): ComplianceMapping
            => (new ComplianceMapping())->setSourceRequirement($source)->setTargetRequirement($target);

        self::assertNull((new MappingGapItem())->setMapping($mapping($global, $global))->getTenant());
        self::assertSame($tenant, (new MappingGapItem())->setMapping($mapping($global, $uploaded))->getTenant());
        self::assertSame($tenant, (new MappingGapItem())->setMapping($mapping($uploaded, $global))->getTenant());
    }

    #[Test]
    public function organisationSeesOwnAndSharedGapsButNeverForeignOnes(): void
    {
        $owner = $this->tenant('owner');
        $other = $this->tenant('other');
        $framework = $this->framework();
        $globalA = $this->requirement($framework, null);
        $globalB = $this->requirement($framework, null);
        $uploaded = $this->requirement($framework, $owner);

        $sharedGap = $this->gap($this->mapping($globalA, $globalB), 'shared gap');
        $ownedGap = $this->gap($this->mapping($globalA, $uploaded), 'owned gap');
        $this->em->flush();
        $sharedId = $sharedGap->getId();
        $ownedId = $ownedGap->getId();

        self::assertSame([$sharedId], $this->visibleHighPriorityIds($other, [$sharedId, $ownedId]));
        self::assertSame([$sharedId, $ownedId], $this->visibleHighPriorityIds($owner, [$sharedId, $ownedId]));

        $this->scopeTo($other);
        $this->em->clear();
        self::assertNull($this->repository()->find($ownedId), 'find() must not load another organisation\'s gap');
        self::assertNotNull($this->repository()->find($sharedId));
    }

    /**
     * @param list<int|null> $candidateIds
     *
     * @return list<int|null>
     */
    private function visibleHighPriorityIds(Tenant $tenant, array $candidateIds): array
    {
        $this->scopeTo($tenant);
        $this->em->clear();
        $ids = array_map(
            static fn(MappingGapItem $gap): ?int => $gap->getId(),
            $this->repository()->findHighPriorityGaps(),
        );
        $visible = array_values(array_intersect($candidateIds, $ids));
        sort($visible);

        return $visible;
    }

    private function scopeTo(Tenant $tenant): void
    {
        $filters = $this->em->getFilters();
        $filter = $filters->isEnabled('tenant_filter') ? $filters->getFilter('tenant_filter') : $filters->enable('tenant_filter');
        $filter->setParameter('tenant_id', (string) $tenant->getId());
    }

    private function repository(): MappingGapItemRepository
    {
        return self::getContainer()->get(MappingGapItemRepository::class);
    }

    private function tenant(string $marker): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Gap ' . $marker);
        $tenant->setCode('gap_' . $marker . '_' . uniqid());
        $this->em->persist($tenant);

        return $tenant;
    }

    private function framework(): ComplianceFramework
    {
        $framework = new ComplianceFramework();
        $framework->setCode('GAPTEST_' . uniqid());
        $framework->setName('Gap isolation framework');
        $framework->setVersion('1');
        $framework->setApplicableIndustry('all');
        $framework->setRegulatoryBody('test');
        $this->em->persist($framework);

        return $framework;
    }

    private function requirement(ComplianceFramework $framework, ?Tenant $uploadTenant): ComplianceRequirement
    {
        $requirement = new ComplianceRequirement();
        $requirement->setFramework($framework);
        $requirement->setRequirementId('R-' . uniqid());
        $requirement->setTitle('Requirement');
        $requirement->setDescription('Requirement text');
        $requirement->setPriority('high');
        $requirement->setUploadTenant($uploadTenant);
        $this->em->persist($requirement);

        return $requirement;
    }

    private function mapping(ComplianceRequirement $source, ComplianceRequirement $target): ComplianceMapping
    {
        $mapping = new ComplianceMapping();
        $mapping->setSourceRequirement($source);
        $mapping->setTargetRequirement($target);
        $mapping->setMappingType('partial');
        $mapping->setMappingPercentage(60);
        $this->em->persist($mapping);

        return $mapping;
    }

    private function gap(ComplianceMapping $mapping, string $description): MappingGapItem
    {
        $gap = new MappingGapItem();
        $gap->setMapping($mapping);
        $gap->setGapType('missing_control');
        $gap->setDescription($description);
        $gap->setPriority('critical');
        $this->em->persist($gap);

        return $gap;
    }
}
