<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Attribute\ApiReadOnlyEvidence;
use App\Entity\ManagementDecision;
use App\Entity\Risk;
use App\Entity\Tenant;
use App\Enum\ManagementDecisionType;
use App\Enum\RiskStatus;
use App\Service\BackupService;
use App\Service\Restore\RestoreDataPurger;
use App\Service\Restore\RestoreEvidencePolicy;
use App\Service\RestoreService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Workflow\Registry;

/**
 * S26: an organisation restore takes no evidence from the backup file.
 *
 * The file is unsigned (S19) and an organisation admin may upload an edited one.
 * Before S26 only the audit log was immutable, so with "clear before restore" an
 * admin without ROLE_TOP_MGMT replaced management decisions, audit freezes, SoA
 * snapshots, risk acceptances and every approved/reported stamp by values of his
 * choosing — evidence that only the step producing it may write (S12, S21).
 *
 * The test finds what is evidence itself, never from a fixed list of the restore:
 *  - every class with a Symfony workflow: its marking (status) is protected;
 *  - every field whose name says "somebody approved / verified / decided … it" on
 *    an organisation's record: protected (#[ApiReadOnlyEvidence]) or reviewed below;
 *  - every class whose name says it is a log, snapshot, decision, acknowledgement,
 *    approval or version: #[EvidenceRecord] or reviewed below;
 * and checks that the restore really keeps them (unit over every class, plus an
 * end-to-end organisation restore of a crafted file).
 */
final class RestoreEvidenceContractTest extends KernelTestCase
{
    private const string EVIDENCE_NAME = '/(approv|verif|decid|confirm|signed|reject|submitt|accepted|attest|reported|notified|closed|reviewed|completed|acknowledg)(At|By|ByUser|ByPerson|ByAuditor|Date|On)?$|^(approv|verif|decid|confirm|signed|reject|submitt|accept|attest|reported|notified|closed|reviewed|completed|acknowledg)/i';

    private const string EVIDENCE_CLASS = '/(Log|Snapshot|Freeze|Decision|Acknowledgement|Approval|ApprovalRequest|Confirmation|Version|Instance|Attempt)$/';

    /** Reviewed: a fact a person states, a slot, a setting or text — not a stamp of a step. class::property => reason */
    private const array REVIEWED_FIELDS = [
        'App\Entity\AccessReviewItem::reviewedRole' => 'the role under review, not a decision',
        'App\Entity\Asset::acceptableUsePolicy' => 'reference to the usage policy, no acceptance',
        'App\Entity\AuditFinding::reportedBy' => 'who found the finding (reporter), not a report to anybody',
        'App\Entity\AuditFinding::reportedByPerson' => 'who found the finding (reporter)',
        'App\Entity\AuditFinding::reportedByDeputyPersons' => 'deputies of the reporter',
        'App\Entity\ChangeRequest::approverPerson' => 'the designated approver (responsibility slot); the approval itself is stamped',
        'App\Entity\ChangeRequest::verificationResults' => 'what the verification found (text); verifiedBy / verifiedDate are stamped',
        'App\Entity\ComplianceRequirementFulfillment::attestationOwnerPerson' => 'who attests (responsibility slot)',
        'App\Entity\DataProtectionImpactAssessment::approvalComments' => 'text beside the approval; status and approval date are protected',
        'App\Entity\DataProtectionImpactAssessment::rejectionReason' => 'text beside the rejection; the status is protected',
        'App\Entity\DataSubjectRequest::rejectionReason' => 'reason typed in the request form; response time is stamped',
        'App\Entity\Document::acknowledgementAudience' => 'setting: who has to acknowledge the policy',
        'App\Entity\DocumentSection::rejectionReason' => 'text beside the rejection; who/when are stamped',
        'App\Entity\DocumentSection::approvalRole' => 'setting: which role signs the section off',
        'App\Entity\InternalAudit::rejectionReason' => 'text beside the rejection; the status is protected',
        'App\Entity\ManagementReview::reviewedBy' => 'participants of the review meeting, typed in the review form',
        'App\Entity\ManagementReview::reviewedByPerson' => 'participants of the review meeting',
        'App\Entity\ManagementReview::reviewedByDeputyPersons' => 'participants of the review meeting',
        'App\Entity\ProcessingActivity::dpiaCompleted' => 'flag typed in the VVT form; the DPIA itself carries its lifecycle',
        'App\Entity\RiskAppetite::approvedBy' => 'typed in RiskAppetiteType (no approval step exists yet) — open point S26',
        'App\Entity\RiskAppetite::approvedAt' => 'typed in RiskAppetiteType (no approval step exists yet) — open point S26',
        'App\Entity\SsoUserApproval::rejectReason' => 'text beside the rejection; who/when are stamped',
        'App\Entity\Supplier::dpaSignedDate' => 'date the supplier signed the AVV, a fact of the contract (P5)',
        'App\Entity\Supplier::outsourcingDueDiligenceCompleted' => 'flag typed in the supplier form',
        'App\Entity\Tenant::dpoAuthorityNotifiedAt' => 'organisation setting typed by the admin; Tenant fields of the instance are PROTECTED_FIELDS',
        'App\Entity\TrainingParticipation::assignedAt' => 'when the training was assigned, not completed',
        'App\Entity\User::completedTours' => 'UI state (guided tours)',
        'App\Entity\Vulnerability::publishedDate' => 'publication date of the CVE, a fact from the advisory',
        'App\Entity\WizardRun::completedAt' => 'technical end of a wizard run, no decision',
        'App\Entity\WizardSession::completedAt' => 'technical end of a wizard session, no decision',
        'App\Entity\WizardSession::completedCategories' => 'UI state of the wizard',
        'App\Entity\WorkflowStep::approverRole' => 'approval chain definition (YAML mirror), not an approval',
        'App\Entity\WorkflowStep::approverUsers' => 'approval chain definition, not an approval',
    ];

    /** Reviewed classes whose name sounds like evidence. class => reason */
    private const array REVIEWED_CLASSES = [
        'App\Entity\Bsi2004ExerciseLog' => 'exercise report edited until confirmed; submit/confirm stamps are #[ApiReadOnlyEvidence]',
        'App\Entity\CryptographicOperation' => 'operations log entered by people (key ceremonies), no step-written decision',
        'App\Entity\KpiSnapshot' => 'derived metrics, recomputed nightly',
        'App\Entity\PortfolioSnapshot' => 'derived metrics, recomputed',
        'App\Entity\ReuseTrendSnapshot' => 'derived metrics, recomputed',
        'App\Entity\PhysicalAccessLog' => 'machine-fed access log; restore of the organisation\'s own rows is the purpose',
        'App\Entity\SsoUserApproval' => 'pending sign-ups; reviewer and time are #[ApiReadOnlyEvidence]',
        'App\Entity\WizardManualConfirmation' => 'current state of a wizard check; who/when are #[ApiReadOnlyEvidence]',
        'App\Entity\ImportRowEvent' => 'no organisation column: never part of an organisation restore',
        'App\Entity\RiskApprovalConfig' => 'setting (approval thresholds), not an approval',
    ];

    /** @return list<ClassMetadata<object>> */
    private function organisationClasses(): array
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $classes = [];
        foreach ($em->getMetadataFactory()->getAllMetadata() as $meta) {
            if (!$meta->isMappedSuperclass && !$meta->isEmbeddedClass && $meta->hasAssociation('tenant')) {
                $classes[] = $meta;
            }
        }

        return $classes;
    }

    private function policy(): RestoreEvidencePolicy
    {
        return self::getContainer()->get(RestoreEvidencePolicy::class);
    }

    #[Test]
    public function everyLifecycleStatusIsProtected(): void
    {
        self::bootKernel();
        $workflows = self::getContainer()->get(Registry::class);
        self::assertInstanceOf(Registry::class, $workflows);
        $checked = 0;
        foreach ($this->organisationClasses() as $meta) {
            $reflection = $meta->getReflectionClass();
            if (!$reflection->isInstantiable()) {
                continue;
            }
            $count = count($workflows->all($reflection->newInstanceWithoutConstructor()));
            if ($count === 0) {
                continue;
            }
            ++$checked;
            self::assertCount(
                $count,
                $this->policy()->lifecycleStatusFields($meta),
                $meta->getName() . ': the marking of each of its workflows must resolve to a mapped field with an initial place',
            );
        }
        self::assertGreaterThan(10, $checked, 'the workflow registry no longer finds the lifecycles');
    }

    #[Test]
    public function evidenceNamedFieldsAreProtectedOrReviewed(): void
    {
        self::bootKernel();
        $problems = [];
        $checked = 0;
        foreach ($this->organisationClasses() as $meta) {
            $class = $meta->getName();
            if (RestoreEvidencePolicy::isEvidenceRecord($class)) {
                continue;
            }
            $protected = $this->policy()->protectedFields($meta);
            foreach ($meta->getReflectionClass()->getProperties() as $property) {
                $name = $property->getName();
                if (preg_match(self::EVIDENCE_NAME, $name) !== 1
                    || !($meta->hasField($name) || $meta->hasAssociation($name))
                ) {
                    continue;
                }
                ++$checked;
                $key = $class . '::' . $name;
                $importable = array_filter(
                    $property->getAttributes(ApiReadOnlyEvidence::class),
                    static fn (\ReflectionAttribute $a): bool => $a->newInstance()->importable,
                ) !== [];
                if (isset($protected[$name]) || $importable || isset(self::REVIEWED_FIELDS[$key])) {
                    continue;
                }
                $problems[] = $key . ' looks like evidence written by a step but an organisation restore takes it from the file: '
                    . 'mark it #[ApiReadOnlyEvidence] (the step writes it) or review it into REVIEWED_FIELDS with the reason';
            }
        }

        self::assertSame([], $problems, implode("\n", $problems));
        self::assertGreaterThan(30, $checked, 'the name pattern no longer finds the known evidence fields');
        foreach (array_keys(self::REVIEWED_FIELDS) as $key) {
            [$class, $name] = explode('::', $key);
            self::assertTrue(property_exists($class, $name), $key . ' no longer exists — drop it from REVIEWED_FIELDS');
        }
    }

    #[Test]
    public function evidenceNamedClassesAreRecordsOrReviewed(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $problems = [];
        $records = 0;
        foreach ($em->getMetadataFactory()->getAllMetadata() as $meta) {
            $class = $meta->getName();
            if (RestoreEvidencePolicy::isEvidenceRecord($class)) {
                ++$records;
                continue;
            }
            $short = $meta->getReflectionClass()->getShortName();
            if (preg_match(self::EVIDENCE_CLASS, $short) === 1 && !isset(self::REVIEWED_CLASSES[$class])) {
                $problems[] = $class . ' sounds like evidence: mark it #[EvidenceRecord] or review it into REVIEWED_CLASSES';
            }
        }

        self::assertSame([], $problems, implode("\n", $problems));
        self::assertGreaterThanOrEqual(10, $records, 'the #[EvidenceRecord] classes are no longer found');
        self::assertTrue(RestoreEvidencePolicy::isEvidenceRecord(\App\Entity\AuditLog::class), 'the audit trail is evidence');
        foreach (array_keys(self::REVIEWED_CLASSES) as $class) {
            self::assertTrue(class_exists($class), $class . ' no longer exists — drop it from REVIEWED_CLASSES');
        }
    }

    /** No organisation restore brings rows of an evidence record in: they are filtered before the writer. */
    #[Test]
    public function evidenceRecordsAreNotRestoredIntoAnOrganisation(): void
    {
        self::bootKernel();
        $purger = self::getContainer()->get(RestoreDataPurger::class);
        $checked = 0;
        foreach ($this->organisationClasses() as $meta) {
            if (!RestoreEvidencePolicy::isEvidenceRecord($meta->getName())) {
                continue;
            }
            ++$checked;
            $short = $meta->getReflectionClass()->getShortName();
            $rows = [['id' => 1, 'tenant_id' => ['id' => 7]]];
            self::assertSame([], $purger->filterEntitiesByScope($rows, [7], $short), $short . ' is restored into an organisation');
            self::assertSame($rows, $purger->filterEntitiesByScope($rows, [], $short), $short . ': an instance restore stays complete');
        }
        self::assertGreaterThanOrEqual(10, $checked);
    }

    /**
     * Over every organisation class with protected fields: whatever the file wrote into
     * them, keep() puts the live value back on an existing record and the fresh value
     * (no evidence, initial place) on a record the restore creates.
     */
    #[Test]
    public function keepPutsEveryProtectedFieldBack(): void
    {
        self::bootKernel();
        $classes = 0;
        foreach ($this->organisationClasses() as $meta) {
            $fields = $this->policy()->protectedFields($meta);
            if ($fields === [] || RestoreEvidencePolicy::isEvidenceRecord($meta->getName())) {
                continue;
            }
            $class = $meta->getName();
            try {
                $entity = new $class();
            } catch (\Throwable) {
                continue;
            }
            ++$classes;
            $live = $this->policy()->capture($entity, $meta);

            $forged = $this->forge($entity, $meta, array_keys($fields));
            self::assertNotSame([], $forged, $class . ': nothing could be forged — the fixture no longer reaches the fields');
            $discarded = $this->policy()->keep($entity, $meta, $live);
            foreach ($forged as $field) {
                self::assertContains($field, $discarded, $class . '::' . $field . ' from the file was not discarded on an existing record');
            }
            self::assertSame([], $this->policy()->keep($entity, $meta, $live), $class . ': live values are not back');

            $this->forge($entity, $meta, array_keys($fields));
            $this->policy()->keep($entity, $meta, null);
            foreach ($fields as $field => $spec) {
                self::assertEquals(
                    $spec['fresh'],
                    $meta->getFieldValue($entity, $field),
                    $class . '::' . $field . ': a record the restore creates must start without evidence / in the initial place',
                );
            }
        }
        self::assertGreaterThan(15, $classes, 'the policy no longer finds the classes with evidence fields');
    }

    /**
     * End to end: an organisation admin edits the backup of his organisation — withdraws
     * a risk acceptance, backdates it, sets the status, drops a management decision and
     * adds a forged one — and restores it with "clear before restore". Nothing changes.
     */
    #[Test]
    #[Group('integration')]
    public function organisationRestoreOfACraftedFileChangesNoEvidence(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->beginTransaction();
        try {
            $suffix = substr(uniqid(), -8);
            $own = (new Tenant())->setName('Evidence ' . $suffix)->setCode('evd_' . $suffix);
            $own->setIsActive(true);
            $em->persist($own);
            $acceptedAt = new \DateTime('2026-03-01 09:00:00');
            $risk = (new Risk())->setTitle('Accepted risk ' . $suffix)->setTenant($own)
                ->setStatus(RiskStatus::Accepted)->setFormallyAccepted(true)->setAcceptanceApprovedAt($acceptedAt);
            $em->persist($risk);
            $decision = (new ManagementDecision())->setTenant($own)->setType(ManagementDecisionType::Policy)
                ->setTitle('Leitlinie ' . $suffix);
            $em->persist($decision);
            $em->flush();
            $ownId = (int) $own->getId();
            $riskId = (int) $risk->getId();
            $decisionId = (int) $decision->getId();

            $backup = self::getContainer()->get(BackupService::class)->createBackup(false, false, false, $own);
            foreach ($backup['data']['Risk'] ?? [] as $i => $row) {
                if (($row['id'] ?? null) === $riskId) {
                    $backup['data']['Risk'][$i] = [
                        'formallyAccepted' => false,
                        'acceptanceApprovedAt' => '2020-01-01 00:00:00',
                        'status' => 'closed',
                        'title' => 'Renamed risk ' . $suffix,
                    ] + $row;
                }
            }
            $backup['data']['Risk'][] = [
                'title' => 'New accepted risk ' . $suffix,
                'status' => 'accepted',
                'formallyAccepted' => true,
                'acceptanceApprovedAt' => '2020-01-01 00:00:00',
                'tenant_id' => ['id' => $ownId],
            ];
            $backup['data']['ManagementDecision'] = [[
                'title' => 'Forged decision ' . $suffix,
                'type' => 'policy',
                'status' => 'approved',
                'tenant_id' => ['id' => $ownId],
            ]];
            $backup['metadata']['sha256'] = hash('sha256', (string) json_encode($backup['data']));

            $restore = self::getContainer()->get(RestoreService::class);
            $restore->restoreFromBackup($backup, ['best_effort' => true, 'clear_before_restore' => true], $own);
            $em->clear();

            $riskAfter = $em->find(Risk::class, $riskId);
            self::assertNotNull($riskAfter, 'a record with evidence is not cleared');
            self::assertSame('Renamed risk ' . $suffix, $riskAfter->getTitle(), 'narrative fields are still restored');
            self::assertTrue($riskAfter->isFormallyAccepted(), 'the acceptance is not withdrawn by the file');
            self::assertSame('2026-03-01 09:00:00', $riskAfter->getAcceptanceApprovedAt()?->format('Y-m-d H:i:s'), 'the acceptance is not backdated');
            self::assertSame(RiskStatus::Accepted, $riskAfter->getStatus(), 'the status moves only through the lifecycle');

            $newRisk = $em->getRepository(Risk::class)->findOneBy(['title' => 'New accepted risk ' . $suffix]);
            if ($newRisk !== null) {
                self::assertFalse($newRisk->isFormallyAccepted(), 'a new record starts without acceptance');
                self::assertNull($newRisk->getAcceptanceApprovedAt());
                self::assertSame(RiskStatus::Identified, $newRisk->getStatus(), 'a new record starts in the initial place');
            }

            self::assertNotNull($em->find(ManagementDecision::class, $decisionId), 'a management decision is not cleared');
            self::assertNull(
                $em->getRepository(ManagementDecision::class)->findOneBy(['title' => 'Forged decision ' . $suffix]),
                'an organisation restore adds no management decision',
            );
            self::assertNotEmpty(array_filter(
                $restore->getWarnings(),
                static fn (string $w): bool => str_contains($w, 'ManagementDecision') && str_contains($w, 'nicht übernommen'),
            ), 'the restore log says the decisions of the file were not taken');
        } finally {
            if ($em->getConnection()->isTransactionActive()) {
                $em->rollback();
            }
        }
    }

    /**
     * Writes a value unlike the live one into each field it can.
     *
     * @param ClassMetadata<object> $meta
     * @param list<string>          $fields
     * @return list<string> the fields forged
     */
    private function forge(object $entity, ClassMetadata $meta, array $fields): array
    {
        $forged = [];
        foreach ($fields as $field) {
            $value = $this->forgedValue($meta, $field, $this->read($meta, $entity, $field));
            if ($value === null) {
                continue;
            }
            $meta->setFieldValue($entity, $field, $value);
            $forged[] = $field;
        }

        return $forged;
    }

    private function read(ClassMetadata $meta, object $entity, string $field): mixed
    {
        try {
            return $meta->getFieldValue($entity, $field);
        } catch (\Error) {
            return null;
        }
    }

    private function forgedValue(ClassMetadata $meta, string $field, mixed $current): mixed
    {
        if ($meta->hasAssociation($field)) {
            $target = $meta->getAssociationTargetClass($field);
            $instance = (new \ReflectionClass($target))->newInstanceWithoutConstructor();
            $idField = self::getContainer()->get(EntityManagerInterface::class)->getClassMetadata($target)->getSingleIdentifierFieldName();
            (new \ReflectionProperty($target, $idField))->setValue($instance, 987654);

            return $instance;
        }
        $mapping = $meta->getFieldMapping($field);
        if ($mapping->enumType !== null) {
            foreach ($mapping->enumType::cases() as $case) {
                if ($case !== $current) {
                    return $case;
                }
            }

            return null;
        }

        return match ($mapping->type) {
            'datetime_immutable', 'date_immutable', 'datetimetz_immutable' => new \DateTimeImmutable('2001-02-03 04:05:06'),
            'datetime', 'date', 'datetimetz' => new \DateTime('2001-02-03 04:05:06'),
            'boolean' => !(bool) $current,
            'integer', 'smallint', 'bigint' => 4242,
            'string', 'text' => 'forged_' . $field,
            default => null,
        };
    }
}
