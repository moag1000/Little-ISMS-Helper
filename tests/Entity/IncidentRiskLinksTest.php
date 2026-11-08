<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\ChangeRequest;
use App\Entity\Incident;
use App\Entity\Person;
use App\Entity\Risk;
use App\Entity\RiskIncidentLink;
use App\Service\Deadline\Source\ChangeApprovalSource;
use App\Repository\ChangeRequestRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * P5 "one list": incident risks are links only; a change approver is a person.
 */
final class IncidentRiskLinksTest extends TestCase
{
    #[Test]
    public function realizedRiskIsAMaterializedLinkOnBothSides(): void
    {
        $incident = new Incident();
        $risk = new Risk();

        $incident->addRealizedRisk($risk);
        $incident->addRealizedRisk($risk);

        self::assertCount(1, $incident->getRiskLinks());
        self::assertSame(RiskIncidentLink::TYPE_MATERIALIZED, $incident->getRiskLinks()->first()->getLinkType());
        self::assertTrue($incident->getRealizedRisks()->contains($risk));
        self::assertTrue($risk->getIncidents()->contains($incident));
        self::assertTrue($risk->hasBeenRealized());

        $incident->removeRealizedRisk($risk);
        self::assertCount(0, $incident->getRiskLinks());
        self::assertCount(0, $risk->getIncidentLinks());
    }

    #[Test]
    public function suspectedLinkIsLinkedButNotRealized(): void
    {
        $incident = new Incident();
        $risk = new Risk();
        $incident->addRealizedRisk($risk);
        $incident->getRiskLinks()->first()->setLinkType('suspected');

        self::assertTrue($incident->getLinkedRisks()->contains($risk));
        self::assertFalse($incident->getRealizedRisks()->contains($risk));
        self::assertFalse($incident->isRiskValidated());
        self::assertSame(0, $risk->getRealizationCount());
    }

    #[Test]
    public function namedApproverHoldsTheChangeApproval(): void
    {
        $source = new ChangeApprovalSource(
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(ChangeRequestRepository::class),
        );
        $approver = (new Person())->setFullName('Alex Muster');
        $change = (new ChangeRequest())->setApproverPerson($approver)->setApprovedBy('alt');

        $responsibility = $source->responsibleOf($change);
        self::assertSame([$approver], $responsibility->holders);
        self::assertSame(['ROLE_ISB', 'ROLE_CISO'], $responsibility->roles);
        self::assertSame('Alex Muster', $change->getEffectiveApprovedBy());

        $unnamed = $source->responsibleOf(new ChangeRequest());
        self::assertSame([], $unnamed->holders);
    }
}
