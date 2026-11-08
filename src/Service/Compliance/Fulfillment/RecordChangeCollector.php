<?php

declare(strict_types=1);

namespace App\Service\Compliance\Fulfillment;

use App\Entity\AuditChecklist;
use App\Entity\Authority\Nis2RegistrationProfile;
use App\Entity\BusinessContinuityPlan;
use App\Entity\ChangeRequest;
use App\Entity\ComplianceRequirement;
use App\Entity\DataBreach;
use App\Entity\Incident;
use App\Entity\InternalAudit;
use App\Entity\ISMSObjective;
use App\Entity\IsmsRegisterEntry;
use App\Entity\ManagementDecision;
use App\Entity\MfaToken;
use App\Entity\Supplier;
use App\Entity\Tenant;
use App\Entity\ThreatIntelligence;
use App\Entity\Training;
use App\Entity\TrainingParticipation;
use App\Entity\User;
use App\Service\Deadline\DeadlineSourceRegistry;
use Closure;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\UnitOfWork;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;
use Symfony\Contracts\Service\ResetInterface;

/**
 * One register entry, one top-management decision, one completed training →
 * the requirements those records answer show it at once (ISO 27001 4.1, 5.1,
 * 7.2, NIS-2 Art. 20 (1), Art. 23, …), not only after the nightly refresh.
 *
 * The record ↔ check-type table below is the only wiring: a new record check
 * reading another entity adds one line. `deadline_adherence` (ISO 27001 8.1,
 * 9.1) needs no line: any record a deadline source knows recomputes it when
 * it is created, deleted, or a date or its status changes. An audit checklist verdict
 * recomputes its own requirement ({@see AuditResultRule}). Like {@see ControlChangeCollector}, a
 * failure never undoes the user's save; the nightly task catches up.
 */
#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
final class RecordChangeCollector implements ResetInterface
{
    /** Entity → wizard check types that read it. */
    public const array CHECK_TYPES = [
        IsmsRegisterEntry::class => ['register_coverage'],
        ManagementDecision::class => ['management_decision'],
        Training::class => ['training_coverage', 'training_participation'],
        TrainingParticipation::class => ['training_participation'],
        Incident::class => ['reporting_duty', 'incident_process', 'record_completeness'],
        DataBreach::class => ['reporting_duty'],
        BusinessContinuityPlan::class => ['record_completeness'],
        Supplier::class => ['record_completeness'],
        ChangeRequest::class => ['record_completeness'],
        ThreatIntelligence::class => ['record_completeness'],
        User::class => ['account_mfa'],
        MfaToken::class => ['account_mfa'],
        InternalAudit::class => ['audit_status'],
    ];

    /**
     * Records saved often for other reasons (a sign-in stamps the account):
     * only a change of these fields counts, besides creation and deletion.
     */
    private const array CHECK_FIELDS = [
        User::class => ['roles', 'isActive', 'authProvider'],
    ];

    /** Fields whose change can move a duty's date or close it (besides every date field). */
    private const array DEADLINE_FIELDS = ['status', 'isActive', 'archived'];

    /** Bookkeeping timestamps: written on every save, never a duty's date. */
    private const array BOOKKEEPING_FIELDS = ['createdAt', 'updatedAt', 'derivedAt', 'lastLoginAt'];

    /**
     * Check types that sum up other checks (ISO 27001 4.4 over clauses 4–10):
     * recomputed whenever any record check above changed.
     */
    public const array SUMMARY_CHECK_TYPES = ['clause_coverage'];

    /** @var array<int, array{tenant: Tenant, types: array<string, true>, audited: array<int, ComplianceRequirement>}> */
    private array $pending = [];

    private bool $running = false;

    /** @var array<class-string, bool> whether any deadline source knows records of the class */
    private array $hasDeadlines = [];

    /**
     * @param Closure(): DeadlineSourceRegistry $deadlines lazy: the sources reach services that use this listener's EntityManager
     */
    public function __construct(
        private readonly FulfillmentDerivationService $derivation,
        private readonly LoggerInterface $logger,
        #[AutowireServiceClosure(DeadlineSourceRegistry::class)]
        private readonly ?Closure $deadlines = null,
    ) {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        if ($this->running) {
            return;
        }
        $uow = $args->getObjectManager()->getUnitOfWork();
        foreach ([...$uow->getScheduledEntityInsertions(), ...$uow->getScheduledEntityUpdates(), ...$uow->getScheduledEntityDeletions()] as $entity) {
            $tenant = self::tenantOf($entity);
            if (!$tenant instanceof Tenant || $tenant->getId() === null) {
                continue;
            }
            $key = (int) $tenant->getId();
            if ($entity instanceof AuditChecklist) {
                // only a verdict counts: generating hundreds of unchecked items recomputes nothing
                if ($entity->getRequirement() instanceof ComplianceRequirement
                    && ($entity->getVerifiedAt() !== null || array_key_exists('verifiedAt', $uow->getEntityChangeSet($entity)))) {
                    $this->pending[$key] ??= ['tenant' => $tenant, 'types' => [], 'audited' => []];
                    $this->pending[$key]['audited'][(int) $entity->getRequirement()->getId()] = $entity->getRequirement();
                }
                continue;
            }
            $types = [];
            foreach (self::CHECK_TYPES as $class => $classTypes) {
                if ($entity instanceof $class && self::relevantChange($entity, $class, $uow)) {
                    $types = [...$types, ...$classTypes];
                }
            }
            if ($this->touchesDeadline($entity, $args->getObjectManager())) {
                $types[] = 'deadline_adherence';
            }
            if ($types === []) {
                continue;
            }
            $this->pending[$key] ??= ['tenant' => $tenant, 'types' => [], 'audited' => []];
            foreach ([...$types, ...self::SUMMARY_CHECK_TYPES] as $type) {
                $this->pending[$key]['types'][$type] = true;
            }
        }
    }

    /** @param class-string $class */
    private static function relevantChange(object $entity, string $class, UnitOfWork $uow): bool
    {
        $fields = self::CHECK_FIELDS[$class] ?? null;
        if ($fields === null || !$uow->isScheduledForUpdate($entity)) {
            return true;
        }

        return array_intersect(array_keys($uow->getEntityChangeSet($entity)), $fields) !== [];
    }

    /**
     * A record of a deadline source that is new, deleted, or got another date
     * or status: the share of duties done on time may have moved.
     */
    private function touchesDeadline(object $entity, EntityManagerInterface $em): bool
    {
        if ($this->deadlines === null) {
            return false;
        }
        $class = $entity::class;
        if (!($this->hasDeadlines[$class] ??= ($this->deadlines)()->allForRecord($entity) !== [])) {
            return false;
        }
        $uow = $em->getUnitOfWork();
        if (!$uow->isScheduledForUpdate($entity)) {
            return true;
        }
        $metadata = $em->getClassMetadata($class);
        foreach (array_keys($uow->getEntityChangeSet($entity)) as $field) {
            if (in_array($field, self::BOOKKEEPING_FIELDS, true)) {
                continue;
            }
            if (in_array($field, self::DEADLINE_FIELDS, true)
                || ($metadata->hasField($field) && in_array($metadata->getTypeOfField($field), [
                    Types::DATE_MUTABLE, Types::DATE_IMMUTABLE, Types::DATETIME_MUTABLE, Types::DATETIME_IMMUTABLE,
                ], true))) {
                return true;
            }
        }

        return false;
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        if ($this->running || $this->pending === []) {
            return;
        }
        $em = $args->getObjectManager();
        $pending = $this->pending;
        $this->pending = [];
        $this->running = true;

        try {
            foreach ($pending as $bucket) {
                if (!$em->isOpen()) {
                    break;
                }
                $changed = $bucket['types'] === [] ? 0 : $this->derivation->refreshForRecords($bucket['tenant'], array_keys($bucket['types']));
                $changed += $this->derivation->refreshAudited($bucket['tenant'], array_values($bucket['audited']));
                if ($changed > 0) {
                    $em->flush();
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Fulfilment refresh after record change failed; the nightly task catches up', [
                'error' => $e->getMessage(),
            ]);
        } finally {
            $this->running = false;
        }
    }

    private static function tenantOf(object $entity): ?Tenant
    {
        return match (true) {
            $entity instanceof IsmsRegisterEntry, $entity instanceof ManagementDecision, $entity instanceof Training,
            $entity instanceof TrainingParticipation, $entity instanceof Incident, $entity instanceof DataBreach,
            $entity instanceof AuditChecklist, $entity instanceof BusinessContinuityPlan,
            $entity instanceof Supplier, $entity instanceof ChangeRequest, $entity instanceof ThreatIntelligence,
            $entity instanceof InternalAudit, $entity instanceof ISMSObjective,
            $entity instanceof Nis2RegistrationProfile => $entity->getTenant(),
            $entity instanceof MfaToken => $entity->getUser()?->getTenant(),
            // Any other tenant-owned record (deadline sources: risks, controls, documents, …).
            is_callable([$entity, 'getTenant']) => ($t = $entity->getTenant()) instanceof Tenant ? $t : null,
            default => null,
        };
    }

    public function reset(): void
    {
        $this->pending = [];
        $this->hasDeadlines = [];
        $this->running = false;
    }
}
