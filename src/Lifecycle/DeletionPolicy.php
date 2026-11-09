<?php

declare(strict_types=1);

namespace App\Lifecycle;

use App\Entity\AccessReviewItem;
use App\Entity\AuditFreeze;
use App\Entity\Bsi2004ExerciseLog;
use App\Entity\Comment;
use App\Entity\DocumentVersion;
use App\Entity\DoraDataFlow;
use App\Entity\DoraExitPlan;
use App\Entity\DoraSubcontractor;
use App\Entity\FourEyesApprovalRequest;
use App\Entity\PolicyAcknowledgement;
use App\Entity\RiskIncidentLink;
use App\Entity\SoaSnapshot;
use App\Entity\Tenant;
use App\Entity\TisaxLicenseConfirmation;
use App\Entity\TrainingParticipation;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Symfony\Component\Workflow\Registry;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * S29: evidence is archived, never deleted (ISO 27001 7.5.3, A.5.33). One rule
 * for every way a record leaves the database — form, bulk action, REST, the
 * retention run — so that an authorised role cannot destroy a record that
 * proves something happened:
 *
 * 1. End state: a record whose lifecycle status is a place marked
 *    `end_state: true` in `config/workflows/<entity>.yaml` (reported audit,
 *    closed incident, notified breach, approved DPIA, accepted risk, completed
 *    training, granted consent …) is kept. The way out is the lifecycle's own
 *    archive step, never a delete. No hand list: a new lifecycle marks its
 *    end places and is covered.
 * 2. Lifecycle-only removal: a workflow with `delete_only_within_hours: N`
 *    (asset: retire → dispose with four eyes, A.5.11 / A.7.14) allows a plain
 *    delete only as a correction of a record created less than N hours ago.
 * 3. Evidence children: a record that rows of an evidence class still point
 *    at (treatment plans of a risk, consents of a processing activity,
 *    participations of a training, findings of an audit …) is not deleted;
 *    those foreign keys are RESTRICT in the database too, so nothing goes by
 *    cascade without an audit row.
 *
 * Every entry point asks {@see refusal()}; {@see \App\EventListener\DeletionGuard}
 * holds it at the flush for whatever a person deletes in a request.
 * Gate 100: tests/Lifecycle/EndStateDeletionContractTest.php.
 */
class DeletionPolicy
{
    /**
     * Records without a lifecycle that are evidence in their own right. With the
     * lifecycle classes that mark end states they form the evidence classes:
     * their foreign keys are never ON DELETE CASCADE, and they block the delete
     * of the record they point at.
     *
     * @var list<class-string>
     */
    public const array EVIDENCE_RECORDS = [
        TrainingParticipation::class,   // ISO 27001 7.2 competence, 7.3 awareness
        PolicyAcknowledgement::class,   // ISO 27001 5.2 / A.5.1 policy communicated
        DocumentVersion::class,         // ISO 27001 7.5.3 version history
        RiskIncidentLink::class,        // ISO 27001 6.1 / A.5.27 lessons from incidents
        FourEyesApprovalRequest::class, // ISO 27001 A.5.3 segregation of duties
        Comment::class,                 // discussion on a record (7.5.3)
        DoraDataFlow::class,            // DORA Art. 28 register of information
        DoraExitPlan::class,            // DORA Art. 28(8)
        DoraSubcontractor::class,       // DORA Art. 28 / 30
        AccessReviewItem::class,        // ISO 27001 A.5.18 access rights reviewed
        TisaxLicenseConfirmation::class, // licence terms accepted
        Bsi2004ExerciseLog::class,      // ISO 22301 8.5 exercise record
        AuditFreeze::class,             // ISO 27001 9.2 frozen audit state
        SoaSnapshot::class,             // ISO 27001 6.1.3 d SoA version
    ];

    /**
     * Evidence rows that are only an open assignment until a date is set: an
     * assigned, not yet attended training is no competence record. Open ones do
     * not block the delete and go with their record ({@see openChildrenOf()}).
     *
     * @var array<class-string, string> class => field that makes the row evidence
     */
    public const array EVIDENCE_ONCE_SET = [
        TrainingParticipation::class => 'completedAt',
    ];

    /** @var array<class-string, list<string>> end-state places per class */
    private array $endStates = [];

    /** @var array<class-string, ?WorkflowInterface> */
    private array $workflows = [];

    /** @var list<class-string>|null */
    private ?array $evidenceClasses = null;

    /** @var array<class-string, list<array{class: class-string, field: string}>> */
    private array $children = [];

    public function __construct(
        private readonly Registry $workflowRegistry,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /** Null when the record may be deleted, else why not. */
    public function refusal(object $record): ?DeletionRefusal
    {
        $workflow = $this->workflowOf($record::class, $record);
        if ($workflow !== null) {
            $status = $this->statusOf($workflow, $record);
            if ($status !== null && \in_array($status, $this->endStatesOf($record::class), true)) {
                return new DeletionRefusal(
                    sprintf('kept as evidence in status "%s" (archive it instead)', $status),
                    'lifecycle.errors.delete_end_state',
                    ['%status%' => $status],
                );
            }
            $hours = $workflow->getMetadataStore()->getWorkflowMetadata()['delete_only_within_hours'] ?? null;
            if (\is_int($hours) && !$this->isFresh($record, $hours)) {
                return new DeletionRefusal(
                    sprintf('leaves only through its lifecycle (delete only within %d hours of creation)', $hours),
                    'lifecycle.errors.delete_needs_end_state',
                    [],
                );
            }
        }

        return $this->childRefusal($record);
    }

    /**
     * Rule 3 alone: evidence rows still point at the record. The retention run
     * asks only this — it is the sanctioned end of a record's keeping period,
     * but never takes evidence of other records with it.
     */
    public function childRefusal(object $record): ?DeletionRefusal
    {
        foreach ($this->evidenceChildrenOf($record::class) as $child) {
            $count = $this->countChildren($child['class'], $child['field'], $record);
            if ($count > 0) {
                $short = substr((string) strrchr($child['class'], '\\'), 1);

                return new DeletionRefusal(
                    sprintf('%d %s record(s) still point at it (evidence)', $count, $short),
                    'lifecycle.errors.delete_has_evidence',
                    ['%count%' => $count, '%type%' => $short],
                );
            }
        }

        return null;
    }

    /**
     * End-state places of the class's lifecycle (`end_state: true`).
     *
     * @return list<string>
     */
    public function endStatesOf(string $class): array
    {
        if (isset($this->endStates[$class])) {
            return $this->endStates[$class];
        }
        $workflow = $this->workflowOf($class);
        $places = [];
        if ($workflow !== null) {
            $store = $workflow->getMetadataStore();
            foreach ($workflow->getDefinition()->getPlaces() as $place) {
                if (($store->getPlaceMetadata($place)['end_state'] ?? false) === true) {
                    $places[] = $place;
                }
            }
        }

        return $this->endStates[$class] = $places;
    }

    /**
     * Lifecycle classes with end states plus {@see EVIDENCE_RECORDS}.
     *
     * @return list<class-string>
     */
    public function evidenceClasses(): array
    {
        if ($this->evidenceClasses !== null) {
            return $this->evidenceClasses;
        }
        $classes = self::EVIDENCE_RECORDS;
        foreach ($this->entityManager->getMetadataFactory()->getAllMetadata() as $metadata) {
            if ($metadata->isMappedSuperclass || $metadata->isEmbeddedClass || $metadata->getReflectionClass()->isAbstract()) {
                continue;
            }
            if (!\in_array($metadata->getName(), $classes, true) && $this->endStatesOf($metadata->getName()) !== []) {
                $classes[] = $metadata->getName();
            }
        }

        return $this->evidenceClasses = $classes;
    }

    /**
     * Owning associations of evidence classes that point at the class and would
     * be lost (or block) when a record of it goes — every ManyToOne/OneToOne
     * whose foreign key is not ON DELETE SET NULL.
     *
     * @return list<array{class: class-string, field: string}>
     */
    public function evidenceChildrenOf(string $class): array
    {
        if (isset($this->children[$class])) {
            return $this->children[$class];
        }
        $found = [];
        foreach ($this->evidenceClasses() as $evidenceClass) {
            $metadata = $this->entityManager->getClassMetadata($evidenceClass);
            foreach ($metadata->getAssociationMappings() as $field => $mapping) {
                if (!$mapping->isToOneOwningSide() || !is_a($class, $mapping->targetEntity, true)) {
                    continue;
                }
                if ($mapping->targetEntity === Tenant::class) {
                    continue; // offboarding an organisation is the instance operator's step, not a record delete
                }
                $onDelete = strtoupper((string) ($mapping->joinColumns[0]->onDelete ?? ''));
                if ($onDelete === 'SET NULL') {
                    continue;
                }
                $found[] = ['class' => $evidenceClass, 'field' => $field];
            }
        }

        return $this->children[$class] = $found;
    }

    /**
     * Open assignments ({@see EVIDENCE_ONCE_SET}) that point at the record and
     * are removed with it.
     *
     * @return list<object>
     */
    public function openChildrenOf(object $record): array
    {
        if (!$this->isStored($record)) {
            return [];
        }
        $open = [];
        foreach ($this->evidenceChildrenOf($record::class) as $child) {
            $doneField = self::EVIDENCE_ONCE_SET[$child['class']] ?? null;
            if ($doneField === null) {
                continue;
            }
            $rows = $this->entityManager->createQueryBuilder()
                ->select('c')
                ->from($child['class'], 'c')
                ->where(sprintf('c.%s = :record', $child['field']))
                ->andWhere(sprintf('c.%s IS NULL', $doneField))
                ->setParameter('record', $record)
                ->getQuery()
                ->getResult();
            array_push($open, ...$rows);
        }

        return $open;
    }

    private function countChildren(string $childClass, string $field, object $record): int
    {
        if (!$this->isStored($record)) {
            return 0;
        }
        $query = $this->entityManager->createQueryBuilder()
            ->select('COUNT(c)')
            ->from($childClass, 'c')
            ->where(sprintf('c.%s = :record', $field))
            ->setParameter('record', $record);
        $doneField = self::EVIDENCE_ONCE_SET[$childClass] ?? null;
        if ($doneField !== null) {
            $query->andWhere(sprintf('c.%s IS NOT NULL', $doneField));
        }

        return (int) $query->getQuery()->getSingleScalarResult();
    }

    private function isStored(object $record): bool
    {
        $id = $this->entityManager->getClassMetadata($record::class)->getIdentifierValues($record);

        return $id !== [] && !\in_array(null, $id, true);
    }

    private function workflowOf(string $class, ?object $subject = null): ?WorkflowInterface
    {
        if (\array_key_exists($class, $this->workflows)) {
            return $this->workflows[$class];
        }
        $subject ??= $this->bareInstance($class);
        $found = null;
        if ($subject !== null) {
            foreach ($this->workflowRegistry->all($subject) as $workflow) {
                if (str_ends_with($workflow->getName(), '_lifecycle')) {
                    $found = $workflow;
                    break;
                }
            }
        }

        return $this->workflows[$class] = $found;
    }

    private function statusOf(WorkflowInterface $workflow, object $record): ?string
    {
        try {
            // The store, not Workflow::getMarking(): that one writes the initial place into an empty record.
            $places = array_keys($workflow->getMarkingStore()->getMarking($record)->getPlaces());
        } catch (\Throwable) {
            return null; // no status yet (marking store cannot read it)
        }

        return isset($places[0]) ? (string) $places[0] : null;
    }

    private function isFresh(object $record, int $hours): bool
    {
        $created = $this->entityManager->getClassMetadata($record::class)->hasField('createdAt')
            ? $this->entityManager->getClassMetadata($record::class)->getFieldValue($record, 'createdAt')
            : null;

        return $created instanceof \DateTimeInterface
            && $created > new \DateTimeImmutable(sprintf('-%d hours', $hours));
    }

    private function bareInstance(string $class): ?object
    {
        if (!class_exists($class)) {
            return null;
        }
        $reflection = new \ReflectionClass($class);

        return $reflection->isInstantiable() ? $reflection->newInstanceWithoutConstructor() : null;
    }

    /** @param ClassMetadata<object> $metadata */
    public static function isTenantOwned(ClassMetadata $metadata): bool
    {
        return $metadata->hasAssociation('tenant')
            && $metadata->getAssociationTargetClass('tenant') === Tenant::class;
    }
}
