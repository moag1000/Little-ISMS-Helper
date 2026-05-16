<?php

declare(strict_types=1);

namespace App\Tests\Form;

use App\Entity\Asset;
use App\Entity\AuditFinding;
use App\Entity\BusinessProcess;
use App\Entity\Incident;
use App\Entity\Risk;
use App\Form\AssetType;
use App\Form\AuditFindingType;
use App\Form\BusinessProcessType;
use App\Form\IncidentType;
use App\Form\RiskType;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormFactoryInterface;

/**
 * audit-s4 P-1 OwnerPicker — anti-regression test.
 *
 * Verifies that after collapsing the hand-rolled owner-cluster into the
 * shared OwnerPickerFormTrait, the resulting forms still expose every
 * child the entity validator and audit-log code paths rely on.
 *
 * If a future cleanup pass drops one of these children without a
 * migration plan, every downstream OwnerResolver / effective<Field>
 * accessor in the entities silently regresses — this test catches it.
 */
final class OwnerPickerAdoptionTest extends KernelTestCase
{
    private FormFactoryInterface $formFactory;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->formFactory = static::getContainer()->get(FormFactoryInterface::class);
    }

    #[Test]
    public function assetFormExposesOwnerCluster(): void
    {
        $form = $this->formFactory->create(AssetType::class, new Asset(), [
            'csrf_protection' => false,
        ]);

        self::assertTrue($form->has('ownerUser'));
        self::assertTrue($form->has('ownerPerson'));
        self::assertTrue($form->has('ownerDeputyPersons'));
        self::assertTrue($form->has('owner'), 'Asset keeps legacy free-text "owner" field via OwnerPicker');
    }

    #[Test]
    public function businessProcessFormExposesProcessOwnerCluster(): void
    {
        $form = $this->formFactory->create(BusinessProcessType::class, new BusinessProcess(), [
            'csrf_protection' => false,
        ]);

        self::assertTrue($form->has('processOwnerUser'));
        self::assertTrue($form->has('processOwnerPerson'));
        self::assertTrue($form->has('processOwnerDeputyPersons'));
        self::assertTrue($form->has('processOwner'), 'BP keeps legacy free-text "processOwner"');
    }

    #[Test]
    public function riskFormExposesRiskOwnerClusterWithoutLegacy(): void
    {
        $form = $this->formFactory->create(RiskType::class, new Risk(), [
            'csrf_protection' => false,
        ]);

        self::assertTrue($form->has('riskOwner'));
        self::assertTrue($form->has('riskOwnerPerson'));
        self::assertTrue($form->has('riskOwnerDeputyPersons'));
    }

    #[Test]
    public function auditFindingFormExposesAssignedCluster(): void
    {
        $form = $this->formFactory->create(AuditFindingType::class, new AuditFinding(), [
            'csrf_protection' => false,
        ]);

        // Owner cluster (assigned-to)
        self::assertTrue($form->has('assignedTo'));
        self::assertTrue($form->has('assignedPerson'));
        self::assertTrue($form->has('assignedDeputyPersons'));

        // Separate Reporter slot must remain untouched (governance role).
        self::assertTrue($form->has('reportedByPerson'));
        self::assertTrue($form->has('reportedByDeputyPersons'));
    }

    #[Test]
    public function incidentFormExposesReportedByClusterPlusResponsiblePerson(): void
    {
        $form = $this->formFactory->create(IncidentType::class, new Incident(), [
            'csrf_protection' => false,
        ]);

        // ReportedBy cluster (the Reporter)
        self::assertTrue($form->has('reportedByUser'));
        self::assertTrue($form->has('reportedByPerson'));
        self::assertTrue($form->has('reportedByDeputyPersons'));
        self::assertTrue($form->has('reportedBy'), 'Incident keeps legacy free-text "reportedBy"');

        // ResponsiblePerson stays separate (Governance, different role).
        self::assertTrue($form->has('responsiblePerson'));
    }
}
