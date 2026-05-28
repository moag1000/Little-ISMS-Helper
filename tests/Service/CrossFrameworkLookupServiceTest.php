<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceMapping;
use App\Entity\ComplianceRequirement;
use App\Repository\ComplianceMappingRepository;
use App\Service\CrossFrameworkLookupService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * @covers \App\Service\CrossFrameworkLookupService
 */
#[AllowMockObjectsWithoutExpectations]
class CrossFrameworkLookupServiceTest extends TestCase
{
    /** @var ComplianceMappingRepository&MockObject */
    private ComplianceMappingRepository $repo;
    private CrossFrameworkLookupService $service;

    protected function setUp(): void
    {
        $this->repo    = $this->createMock(ComplianceMappingRepository::class);
        $this->service = new CrossFrameworkLookupService($this->repo);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function makeFramework(int $id, string $code): ComplianceFramework
    {
        $fw = new ComplianceFramework();
        $this->setId($fw, $id);
        $fw->setCode($code);

        return $fw;
    }

    private function makeRequirement(int $id, string $reqId, ComplianceFramework $fw): ComplianceRequirement
    {
        $req = new ComplianceRequirement();
        $this->setId($req, $id);
        $req->setRequirementId($reqId);
        $req->setFramework($fw);

        return $req;
    }

    private function makeMapping(
        int $id,
        ComplianceRequirement $source,
        ComplianceRequirement $target,
        int $percentage = 100,
        bool $bidirectional = false,
    ): ComplianceMapping {
        $mapping = new ComplianceMapping();
        $this->setId($mapping, $id);
        $mapping->setSourceRequirement($source);
        $mapping->setTargetRequirement($target);
        $mapping->setMappingPercentage($percentage);
        $mapping->setBidirectional($bidirectional);

        return $mapping;
    }

    private function setId(object $entity, int $id): void
    {
        // Works for all three entity types — they all use `id`.
        // setAccessible() is a no-op since PHP 8.1 and deprecated in 8.5 — omit it.
        $rp = new ReflectionProperty($entity, 'id');
        $rp->setValue($entity, $id);
    }

    // ─── Tests ────────────────────────────────────────────────────────────────

    #[Test]
    public function forwardLookupReturnsOnlyOutboundRows(): void
    {
        $fwTisax = $this->makeFramework(1, 'VDA-ISA-6');
        $fwIso   = $this->makeFramework(2, 'ISO27001-2022');

        $source = $this->makeRequirement(10, 'ISA-1.1.1', $fwTisax);
        $target = $this->makeRequirement(20, 'A.5.1', $fwIso);

        $mapping = $this->makeMapping(100, $source, $target, 100, false);

        $this->repo->method('findByEitherSourceOrTarget')->willReturn([$mapping]);

        $result = $this->service->findMappingsForward($source);

        self::assertCount(1, $result);
        self::assertSame('forward', $result[0]['direction']);
        self::assertSame($mapping, $result[0]['mapping']);
        self::assertSame(1, $result[0]['depth']);
    }

    #[Test]
    public function forwardLookupFiltersbyFrameworkCode(): void
    {
        $fwTisax   = $this->makeFramework(1, 'VDA-ISA-6');
        $fwIso     = $this->makeFramework(2, 'ISO27001-2022');
        $fwBsi     = $this->makeFramework(3, 'BSI-200-2');

        $source    = $this->makeRequirement(10, 'ISA-1.1.1', $fwTisax);
        $isoTarget = $this->makeRequirement(20, 'A.5.1', $fwIso);
        $bsiTarget = $this->makeRequirement(30, 'ORP.2', $fwBsi);

        $mappingIso = $this->makeMapping(100, $source, $isoTarget, 100, false);
        $mappingBsi = $this->makeMapping(101, $source, $bsiTarget, 80, false);

        // Repo returns both regardless — service filters by framework code
        $this->repo->method('findByEitherSourceOrTarget')->willReturn([$mappingIso, $mappingBsi]);

        $result = $this->service->findMappingsForward($source, 'ISO27001-2022');

        self::assertCount(1, $result);
        self::assertSame($mappingIso, $result[0]['mapping']);
    }

    #[Test]
    public function reverseLookupExcludesNonBidirectionalMappings(): void
    {
        $fwTisax = $this->makeFramework(1, 'VDA-ISA-6');
        $fwIso   = $this->makeFramework(2, 'ISO27001-2022');

        $source = $this->makeRequirement(10, 'ISA-1.1.1', $fwTisax);
        $target = $this->makeRequirement(20, 'A.5.1', $fwIso);

        // bidirectional = false → reverse must return nothing
        $mapping = $this->makeMapping(100, $source, $target, 100, false);

        $this->repo->method('findByEitherSourceOrTarget')->willReturn([$mapping]);

        $result = $this->service->findMappingsReverse($target);

        self::assertCount(0, $result);
    }

    #[Test]
    public function reverseLookupReturnsBidirectionalMappings(): void
    {
        $fwTisax = $this->makeFramework(1, 'VDA-ISA-6');
        $fwIso   = $this->makeFramework(2, 'ISO27001-2022');

        $source = $this->makeRequirement(10, 'ISA-1.1.1', $fwTisax);
        $target = $this->makeRequirement(20, 'A.5.1', $fwIso);

        // bidirectional = true → reverse must include this
        $mapping = $this->makeMapping(100, $source, $target, 100, true);

        $this->repo->method('findByEitherSourceOrTarget')->willReturn([$mapping]);

        $result = $this->service->findMappingsReverse($target);

        self::assertCount(1, $result);
        self::assertSame('reverse', $result[0]['direction']);
        self::assertSame($mapping, $result[0]['mapping']);
    }

    #[Test]
    public function findEquivalentsDeduplicatesKeepingStrongest(): void
    {
        $fwTisax = $this->makeFramework(1, 'VDA-ISA-6');
        $fwIso   = $this->makeFramework(2, 'ISO27001-2022');

        $source  = $this->makeRequirement(10, 'ISA-1.1.1', $fwTisax);
        $target1 = $this->makeRequirement(20, 'A.5.1', $fwIso);

        // Two mappings to same target with different percentages
        $weakMapping   = $this->makeMapping(100, $source, $target1, 60, true);
        $strongMapping = $this->makeMapping(101, $source, $target1, 100, true);

        // Repo returns both
        $this->repo->method('findByEitherSourceOrTarget')->willReturn([$weakMapping, $strongMapping]);

        $result = $this->service->findEquivalents($source);

        // Must deduplicate — only one entry for target1
        self::assertCount(1, $result);
        // Must keep the strongest (100%)
        self::assertSame(100, $result[0]['mapping']->getFinalPercentage());
    }

    #[Test]
    public function findEquivalentsUsesInMemoryCache(): void
    {
        $fwTisax = $this->makeFramework(1, 'VDA-ISA-6');
        $fwIso   = $this->makeFramework(2, 'ISO27001-2022');

        $source = $this->makeRequirement(10, 'ISA-1.1.1', $fwTisax);
        $target = $this->makeRequirement(20, 'A.5.1', $fwIso);
        $mapping = $this->makeMapping(100, $source, $target, 100, false);

        // First findEquivalents: calls repo twice (forward + reverse sub-lookups).
        // Second findEquivalents with same args: hits the in-memory cache → 0 additional repo calls.
        // Total expected repo invocations = exactly 2.
        $this->repo->expects(self::exactly(2))
            ->method('findByEitherSourceOrTarget')
            ->willReturn([$mapping]);

        $this->service->findEquivalents($source);
        $this->service->findEquivalents($source); // second call must hit cache — no additional repo calls
    }

    #[Test]
    public function findTransitiveEquivalentsWalksDepth2(): void
    {
        $fwA = $this->makeFramework(1, 'FW-A');
        $fwB = $this->makeFramework(2, 'FW-B');
        $fwC = $this->makeFramework(3, 'FW-C');

        $reqA = $this->makeRequirement(10, 'A-1', $fwA);
        $reqB = $this->makeRequirement(20, 'B-1', $fwB);
        $reqC = $this->makeRequirement(30, 'C-1', $fwC);

        $mappingAB = $this->makeMapping(100, $reqA, $reqB, 100, true);
        $mappingBC = $this->makeMapping(101, $reqB, $reqC, 100, true);

        // repo returns correct row for each "source" requirement
        $this->repo->method('findByEitherSourceOrTarget')
            ->willReturnCallback(function (ComplianceRequirement $req) use ($reqA, $reqB, $mappingAB, $mappingBC): array {
                return match ($req->getId()) {
                    10 => [$mappingAB],  // reqA → reqB
                    20 => [$mappingBC],  // reqB → reqC
                    default => [],
                };
            });

        $result = $this->service->findTransitiveEquivalents($reqA, maxDepth: 2);

        // Must find both reqB (depth 1) and reqC (depth 2)
        $foundIds = array_map(fn (array $e): int => $e['requirement']->getId(), $result);
        self::assertContains(20, $foundIds); // reqB
        self::assertContains(30, $foundIds); // reqC
    }

    #[Test]
    public function findTransitiveEquivalentsGuardsCycles(): void
    {
        $fwA = $this->makeFramework(1, 'FW-A');
        $fwB = $this->makeFramework(2, 'FW-B');

        $reqA = $this->makeRequirement(10, 'A-1', $fwA);
        $reqB = $this->makeRequirement(20, 'B-1', $fwB);

        // Cyclic: A → B (bidirectional, so reverse B → A also returned)
        $mappingAB = $this->makeMapping(100, $reqA, $reqB, 100, true);

        $this->repo->method('findByEitherSourceOrTarget')->willReturn([$mappingAB]);

        // Must not infinite-loop; cycle guard prevents revisiting reqA from reqB
        $result = $this->service->findTransitiveEquivalents($reqA, maxDepth: 3);

        // reqB found at depth 1; reqA NOT re-added (visited)
        $foundIds = array_map(fn (array $e): int => $e['requirement']->getId(), $result);
        self::assertContains(20, $foundIds);
        self::assertNotContains(10, $foundIds); // reqA excluded by cycle guard
    }
}
