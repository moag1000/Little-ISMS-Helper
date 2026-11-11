<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\User;
use App\Security\ActingUser;
use App\Service\AuditLogger;
use App\Service\WriteRule\CorrectionReason;
use App\Service\WriteRule\WriteRuleCheck;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Contracts\Service\ResetInterface;

/**
 * S22: one write rule for every writer. Rules that used to live in a FormType
 * were bypassed by the bulk import, REST and the assistant (MCP `save_record`);
 * here, at the flush every write passes, they hold for all of them:
 *
 * - `#[CreateOnly]` fields of a stored record change only by a manager
 *   (incident / data breach `detectedAt`, the risk of a treatment plan), and
 *   only with a reason (S28, F-06: {@see CorrectionReason} — form field, or the
 *   `X-Correction-Reason` header on REST/MCP), which goes into the audit log
 *   with before and after;
 * - `#[ManagerOrResponsibleField]` fields (incident classification, asset
 *   protection need, supplier criticality) and who is responsible for the
 *   record change only by a manager or the person responsible before the change.
 *
 * Web forms show those fields read-only ({@see \App\Form\Extension\WriteRuleFormExtension});
 * this is the backstop: 403, nothing is written. Checked for the acting person
 * ({@see ActingUser}: the logged-in user, or the one who started an async job — S28,
 * F-07); console commands and the scheduler act for nobody.
 * Gate 96: tests/Security/WritePathParityTest.php.
 */
#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
final class WriteRuleGuard implements ResetInterface
{
    /** @var list<array{type: string, record: object, old: array<string, mixed>, new: array<string, mixed>, reason: string}> */
    private array $corrections = [];

    public function __construct(
        private readonly Security $security,
        private readonly ActingUser $actingUser,
        private readonly CorrectionReason $correctionReason,
        // Lazy: the check asks every deadline source; some reach services that use this EntityManager.
        #[Autowire(lazy: true)]
        private readonly WriteRuleCheck $check,
        #[Autowire(lazy: true)]
        private readonly AuditLogger $auditLogger,
    ) {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $user = $this->actingUser->current();
        if (!$user instanceof User) {
            return;
        }
        $em = $args->getObjectManager();
        $uow = $em->getUnitOfWork();

        /** @var array<int, object> $records */
        $records = [];
        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            $records[spl_object_id($entity)] = $entity;
        }
        foreach ([...$uow->getScheduledCollectionUpdates(), ...$uow->getScheduledCollectionDeletions()] as $collection) {
            $owner = $collection->getOwner();
            if ($owner !== null) {
                $records[spl_object_id($owner)] = $owner;
            }
        }

        $isManager = null;
        foreach ($records as $record) {
            $changes = WriteRuleCheck::ruledChanges($em, $record);
            if ($changes === []) {
                continue;
            }
            $isManager ??= $this->security->isGrantedForUser($user, SelfAssignmentGuard::MANAGER_ROLE);
            $refusal = $this->check->refusal($record, $user, $isManager, $changes);
            if ($refusal !== null) {
                throw new AccessDeniedException($refusal . ' (S22)');
            }
            $corrected = WriteRuleCheck::createOnlyChanges($record, $changes);
            if ($corrected === []) {
                continue;
            }
            $reason = $this->correctionReason->current();
            if ($reason === null) {
                throw new AccessDeniedException(sprintf(
                    '%s.%s is set when the record is created; a correction needs a reason (S28).',
                    (new \ReflectionClass($record))->getShortName(),
                    array_key_first($corrected),
                ));
            }
            $meta = $em->getClassMetadata($record::class);
            $this->corrections[] = [
                'type' => (new \ReflectionClass($record))->getShortName(),
                'record' => $record,
                'old' => array_map(self::plain(...), $corrected),
                'new' => array_combine(
                    array_keys($corrected),
                    array_map(static fn (string $f): mixed => self::plain($meta->getFieldValue($record, $f)), array_keys($corrected)),
                ),
                'reason' => $reason,
            ];
        }
    }

    /** The correction and its reason go into the audit log once they are stored. */
    public function postFlush(PostFlushEventArgs $args): void
    {
        $corrections = $this->corrections;
        $this->corrections = [];
        foreach ($corrections as $c) {
            $id = is_callable([$c['record'], 'getId']) ? $c['record']->getId() : null;
            $this->auditLogger->aboutRecord($c['record'], fn () => $this->auditLogger->logCustom(
                'create_only_corrected',
                $c['type'],
                is_int($id) ? $id : null,
                $c['old'],
                $c['new'] + ['reason' => $c['reason']],
                sprintf('%s: create-only value corrected — %s', $c['type'], $c['reason']),
            ));
        }
    }

    public function reset(): void
    {
        $this->corrections = [];
    }

    private static function plain(mixed $value): mixed
    {
        return match (true) {
            $value instanceof \DateTimeInterface => $value->format(\DateTimeInterface::ATOM),
            $value instanceof \BackedEnum => $value->value,
            is_object($value) && is_callable([$value, 'getId']) => $value->getId(),
            is_scalar($value), $value === null => $value,
            default => get_debug_type($value),
        };
    }
}
