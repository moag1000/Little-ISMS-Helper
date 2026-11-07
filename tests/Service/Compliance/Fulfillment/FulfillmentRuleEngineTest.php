<?php

declare(strict_types=1);

namespace App\Tests\Service\Compliance\Fulfillment;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceMapping;
use App\Entity\ComplianceRequirement;
use App\Entity\ComplianceRequirementFulfillment;
use App\Entity\Control;
use App\Entity\Tenant;
use App\Repository\ComplianceMappingRepository;
use App\Repository\ComplianceRequirementFulfillmentRepository;
use App\Repository\ControlRepository;
use App\Service\Compliance\Fulfillment\FulfillmentRuleEngine;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class FulfillmentRuleEngineTest extends TestCase
{
    private Tenant $tenant;
    private Tenant $otherTenant;
    /** @var list<Control> */
    private array $controls = [];
    /** @var array<int, list<ComplianceMapping>> requirement id → inbound mappings */
    private array $inbound = [];
    /** @var array<int, list<ComplianceMapping>> requirement id → outbound mappings */
    private array $outbound = [];
    /** @var array<int, ComplianceRequirementFulfillment> requirement id → stored fulfilment */
    private array $stored = [];
    private int $nextId = 1;

    protected function setUp(): void
    {
        $this->tenant = $this->withId(new Tenant());
        $this->otherTenant = $this->withId(new Tenant());
    }

    #[Test]
    public function annexARequirementFollowsItsOwnControl(): void
    {
        $this->control('5.1', 'implemented');
        $req = $this->requirement('ISO27001', 'A.5.1');

        $derivation = $this->engine()->derive($req, $this->tenant);

        self::assertNotNull($derivation);
        self::assertSame(100, $derivation->percentage);
        self::assertSame(FulfillmentRuleEngine::KIND_CONTROLS, $derivation->kind);
        self::assertTrue($derivation->applicable);
    }

    #[Test]
    public function soaExclusionMakesTheAnnexARequirementNotApplicable(): void
    {
        $this->control('5.1', 'not_started', applicable: false, justification: 'Kein Cloud-Betrieb');
        $req = $this->requirement('ISO27001', 'A.5.1');

        $derivation = $this->engine()->derive($req, $this->tenant);

        self::assertNotNull($derivation);
        self::assertNull($derivation->percentage);
        self::assertFalse($derivation->applicable);
        self::assertSame('Kein Cloud-Betrieb', $derivation->applicabilityJustification);
    }

    #[Test]
    public function catalogueControlsAreAveragedAndExcludedOnesDropOut(): void
    {
        $this->control('5.1', 'implemented');
        $this->control('5.2', 'in_progress');
        $this->control('5.3', 'not_started', applicable: false);
        $req = $this->requirement('NIS2', '21.2.a', ['iso_controls' => ['A.5.1', '5.2', '5.3']]);

        $derivation = $this->engine()->derive($req, $this->tenant);

        self::assertNotNull($derivation);
        self::assertSame(75, $derivation->percentage); // (100 + 50) / 2, 5.3 excluded
        self::assertCount(3, $derivation->basis);
        self::assertNull($derivation->applicable, 'Only an Annex-A identity decides applicability.');
    }

    #[Test]
    public function controlsOfAnotherOrganisationNeverCount(): void
    {
        $foreign = $this->withId((new Control())
            ->setTenant($this->otherTenant)
            ->setControlId('5.1')
            ->setName('Foreign')
            ->setImplementationStatus('implemented'));
        $req = $this->requirement('TISAX', '1.1.1');
        $req->addMappedControl($foreign);

        self::assertNull($this->engine()->derive($req, $this->tenant));
    }

    #[Test]
    public function mappingCarriesTheSourceValueTimesTheMappingPercentage(): void
    {
        $this->control('5.1', 'implemented');
        $source = $this->requirement('ISO27001', 'A.5.1');
        $target = $this->requirement('BSI_GRUNDSCHUTZ', 'ISMS.1.A1');
        $this->mapping($source, $target, 80);

        $derivation = $this->engine()->derive($target, $this->tenant);

        self::assertNotNull($derivation);
        self::assertSame(FulfillmentRuleEngine::KIND_MAPPING, $derivation->kind);
        self::assertSame(80, $derivation->percentage);
        self::assertSame('A.5.1', $derivation->basis[0]['ref']);
    }

    #[Test]
    public function aStoredFulfilmentIsTheMappingSource(): void
    {
        $source = $this->requirement('NIS2', '21.2.b');
        $target = $this->requirement('DORA', 'Art.9');
        $this->mapping($source, $target, 100);
        $this->stored[(int) $source->getId()] = (new ComplianceRequirementFulfillment())
            ->setTenant($this->tenant)
            ->setRequirement($source)
            ->setFulfillmentPercentage(60);

        self::assertSame(60, $this->engine()->derive($target, $this->tenant)?->percentage);
    }

    #[Test]
    public function bidirectionalMappingsWorkBothWaysAndCyclesStop(): void
    {
        $this->control('5.1', 'implemented');
        $iso = $this->requirement('ISO27001', 'A.5.1');
        $nis = $this->requirement('NIS2', '21.2.c');
        $this->mapping($nis, $iso, 90, bidirectional: true);
        $this->mapping($iso, $nis, 90, bidirectional: true);

        self::assertSame(90, $this->engine()->derive($nis, $this->tenant)?->percentage);
    }

    #[Test]
    public function mappingsOwnedByAnotherOrganisationAreIgnored(): void
    {
        $this->control('5.1', 'implemented');
        $source = $this->requirement('ISO27001', 'A.5.1');
        $target = $this->requirement('CUSTOM', 'X.1');
        $target->setUploadTenant($this->otherTenant);
        $this->mapping($source, $target, 100);

        self::assertNull($this->engine()->derive($target, $this->tenant));
    }

    #[Test]
    public function noDataMeansNoRule(): void
    {
        self::assertNull($this->engine()->derive($this->requirement('NIS2', '23.1'), $this->tenant));
    }

    #[Test]
    public function controlValueScale(): void
    {
        self::assertSame(100, FulfillmentRuleEngine::controlValue((new Control())->setImplementationStatus('verified')));
        self::assertSame(40, FulfillmentRuleEngine::controlValue((new Control())->setImplementationStatus('in_progress')->setImplementationPercentage(40)));
        self::assertSame(50, FulfillmentRuleEngine::controlValue((new Control())->setImplementationStatus('in_progress')));
        self::assertSame(0, FulfillmentRuleEngine::controlValue((new Control())->setImplementationStatus('not_started')));
        self::assertSame('5.1', FulfillmentRuleEngine::normalise(' A.5.1 '));
    }

    private function engine(): FulfillmentRuleEngine
    {
        $controls = $this->createStub(ControlRepository::class);
        $controls->method('findByTenant')->willReturnCallback(
            fn (Tenant $t): array => array_values(array_filter(
                $this->controls,
                static fn (Control $c): bool => $c->getTenant() === $t,
            )),
        );

        $mappings = $this->createStub(ComplianceMappingRepository::class);
        $mappings->method('findMappingsToRequirement')->willReturnCallback(
            fn (ComplianceRequirement $r): array => $this->inbound[(int) $r->getId()] ?? [],
        );
        $mappings->method('findMappingsFromRequirement')->willReturnCallback(
            fn (ComplianceRequirement $r): array => $this->outbound[(int) $r->getId()] ?? [],
        );

        $fulfillments = $this->createStub(ComplianceRequirementFulfillmentRepository::class);
        $fulfillments->method('findOneBy')->willReturnCallback(
            fn (array $criteria): ?ComplianceRequirementFulfillment => $this->stored[(int) $criteria['requirement']->getId()] ?? null,
        );

        return new FulfillmentRuleEngine($controls, $mappings, $fulfillments);
    }

    private function control(string $id, string $status, ?bool $applicable = true, ?string $justification = null): Control
    {
        $control = $this->withId((new Control())
            ->setTenant($this->tenant)
            ->setControlId($id)
            ->setName('Control ' . $id)
            ->setImplementationStatus($status)
            ->setApplicable($applicable)
            ->setJustification($justification));
        $this->controls[] = $control;

        return $control;
    }

    /**
     * @param array<string, mixed> $dataSource
     */
    private function requirement(string $framework, string $id, array $dataSource = []): ComplianceRequirement
    {
        $fw = (new ComplianceFramework())->setCode($framework)->setName($framework);

        return $this->withId((new ComplianceRequirement())
            ->setFramework($fw)
            ->setRequirementId($id)
            ->setDataSourceMapping($dataSource));
    }

    private function mapping(ComplianceRequirement $source, ComplianceRequirement $target, int $pct, bool $bidirectional = false): void
    {
        $mapping = (new ComplianceMapping())
            ->setSourceRequirement($source)
            ->setTargetRequirement($target)
            ->setMappingPercentage($pct)
            ->setBidirectional($bidirectional);
        $this->inbound[(int) $target->getId()][] = $mapping;
        $this->outbound[(int) $source->getId()][] = $mapping;
    }

    /**
     * @template T of object
     * @param T $entity
     * @return T
     */
    private function withId(object $entity): object
    {
        (new ReflectionProperty($entity, 'id'))->setValue($entity, $this->nextId++);

        return $entity;
    }
}
