<?php

declare(strict_types=1);

namespace App\Tests\Service\Compliance\Fulfillment;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceRequirement;
use App\Entity\ComplianceRequirementFulfillment;
use App\Entity\Tenant;
use App\Enum\ComplianceRequirementFulfillmentStatus;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\ComplianceRequirementFulfillmentRepository;
use App\Repository\ComplianceRequirementRepository;
use App\Service\AuditLogger;
use App\Service\Compliance\Fulfillment\AffectedRequirementFinder;
use App\Service\Compliance\Fulfillment\FulfillmentDerivation;
use App\Service\Compliance\Fulfillment\FulfillmentDerivationService;
use App\Service\Compliance\Fulfillment\FulfillmentRuleEngine;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class FulfillmentDerivationServiceTest extends TestCase
{
    private ?FulfillmentDerivation $rule = null;

    #[Test]
    public function aNewRowTakesTheRuleValue(): void
    {
        $this->rule = new FulfillmentDerivation(100, FulfillmentRuleEngine::KIND_CONTROLS, [], true);
        $f = $this->fulfillment();

        self::assertTrue($this->service()->apply($f));
        self::assertSame(100, $f->getFulfillmentPercentage());
        self::assertSame(ComplianceRequirementFulfillmentStatus::Implemented->value, $f->getStatus());
        self::assertTrue($f->isDerived());
        self::assertFalse($f->isOverridden());
    }

    #[Test]
    public function anOlderHandValueBecomesALegacyOverrideWithReviewDate(): void
    {
        $this->rule = new FulfillmentDerivation(40, FulfillmentRuleEngine::KIND_CONTROLS, []);
        $f = $this->fulfillment(stored: true)->setFulfillmentPercentage(80)
            ->setStatus(ComplianceRequirementFulfillmentStatus::InProgress);
        $now = new DateTimeImmutable('2026-11-07 10:00');

        $this->service()->apply($f, $now);

        self::assertSame(80, $f->getFulfillmentPercentage(), 'The hand value is kept.');
        self::assertSame(40, $f->getDerivedPercentage());
        self::assertSame(ComplianceRequirementFulfillment::OVERRIDE_LEGACY, $f->getOverrideSource());
        self::assertSame('2027-02-05', $f->getOverrideReviewDue()?->format('Y-m-d'));
    }

    #[Test]
    public function anUntouchedStoredRowSimplyFollowsTheRule(): void
    {
        $this->rule = new FulfillmentDerivation(40, FulfillmentRuleEngine::KIND_CONTROLS, []);
        $f = $this->fulfillment(stored: true);

        $this->service()->apply($f);

        self::assertSame(40, $f->getFulfillmentPercentage());
        self::assertFalse($f->isOverridden());
    }

    #[Test]
    public function overrideHoldsUntilTheRuleIsFollowedAgain(): void
    {
        $this->rule = new FulfillmentDerivation(30, FulfillmentRuleEngine::KIND_MAPPING, []);
        $f = $this->fulfillment();
        $service = $this->service(expectAudit: 2);
        $service->apply($f);

        $service->override($f, 90, 'Externer Penetrationstest belegt die Umsetzung', new DateTimeImmutable('+6 months'), null);
        $service->apply($f);
        self::assertSame(90, $f->getFulfillmentPercentage());
        self::assertSame('Externer Penetrationstest belegt die Umsetzung', $f->getOverrideReason());

        $service->clearOverride($f, null);
        self::assertFalse($f->isOverridden());
        self::assertSame(30, $f->getFulfillmentPercentage());
    }

    #[Test]
    public function soaExclusionSetsNotApplicableWithItsJustification(): void
    {
        $this->rule = new FulfillmentDerivation(null, FulfillmentRuleEngine::KIND_CONTROLS, [], false, 'Kein Rechenzentrum');
        $f = $this->fulfillment();

        $this->service()->apply($f);

        self::assertFalse($f->isApplicable());
        self::assertSame('Kein Rechenzentrum', $f->getApplicabilityJustification());
    }

    #[Test]
    public function handAssessmentOnlyWithoutRule(): void
    {
        $f = $this->fulfillment();
        $service = $this->service();
        $service->apply($f);
        self::assertTrue($service->setManual($f, 70));
        self::assertSame(70, $f->getFulfillmentPercentage());

        $this->rule = new FulfillmentDerivation(20, FulfillmentRuleEngine::KIND_CONTROLS, []);
        $service->apply($f);
        self::assertFalse($service->setManual($f, 70), 'A computed requirement takes an override, not a slider value.');
    }

    private function service(int $expectAudit = 0): FulfillmentDerivationService
    {
        $engine = $this->createStub(FulfillmentRuleEngine::class);
        $engine->method('derive')->willReturnCallback(fn (): ?FulfillmentDerivation => $this->rule);

        $audit = $expectAudit > 0 ? $this->createMock(AuditLogger::class) : $this->createStub(AuditLogger::class);
        if ($audit instanceof \PHPUnit\Framework\MockObject\MockObject) {
            $audit->expects(self::exactly($expectAudit))->method('logCustom');
        }

        return new FulfillmentDerivationService(
            $engine,
            $this->createStub(AffectedRequirementFinder::class),
            $this->createStub(ComplianceRequirementFulfillmentRepository::class),
            $this->createStub(ComplianceRequirementRepository::class),
            $this->createStub(ComplianceFrameworkRepository::class),
            $this->createStub(EntityManagerInterface::class),
            $audit,
        );
    }

    private function fulfillment(bool $stored = false): ComplianceRequirementFulfillment
    {
        $requirement = (new ComplianceRequirement())
            ->setFramework((new ComplianceFramework())->setCode('NIS2')->setName('NIS2'))
            ->setRequirementId('21.2.a');
        $f = (new ComplianceRequirementFulfillment())
            ->setTenant(new Tenant())
            ->setRequirement($requirement);
        if ($stored) {
            (new ReflectionProperty($f, 'id'))->setValue($f, 42);
        }

        return $f;
    }
}
