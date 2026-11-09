<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Controller\CommentController;
use App\Entity\Comment;
use App\Entity\User;
use App\Lifecycle\DeletionPolicy;
use App\Lifecycle\DeletionRefusedException;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * S29: the {@see DeletionPolicy} at the flush every delete passes.
 *
 * - While a person acts in a request (form, bulk action, REST, the assistant
 *   over MCP) a scheduled delete of a record the policy keeps — end state,
 *   lifecycle-only removal, evidence children — throws
 *   {@see DeletionRefusedException} before anything is written. Console,
 *   scheduler and workers act for nobody (as {@see WriteRuleGuard}); the
 *   system paths that delete (retention, Quick-Fix) ask the policy themselves.
 * - Always: comments on a deleted record go with it (no orphan rows that keep
 *   personal data and resurface on a reused id). Their deletion is audited
 *   without the text (AuditLogSubscriber).
 */
#[AsDoctrineListener(event: Events::onFlush)]
final class DeletionGuard
{
    /** @var array<class-string, string>|null class => comment entity type */
    private ?array $commentTypes = null;

    public function __construct(
        private readonly Security $security,
        private readonly RequestStack $requestStack,
        // Lazy: the policy reads the workflow registry and the metadata of every class.
        #[Autowire(lazy: true)]
        private readonly DeletionPolicy $policy,
    ) {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $em = $args->getObjectManager();
        $uow = $em->getUnitOfWork();
        $deletions = $uow->getScheduledEntityDeletions();
        if ($deletions === []) {
            return;
        }

        if ($this->requestStack->getCurrentRequest() !== null && $this->security->getUser() instanceof User) {
            foreach ($deletions as $record) {
                $refusal = $this->policy->refusal($record);
                if ($refusal !== null) {
                    $class = $em->getClassMetadata($record::class)->getName();
                    throw new DeletionRefusedException($refusal, substr((string) strrchr($class, '\\'), 1));
                }
            }
        }

        $this->commentTypes ??= array_flip(CommentController::entityTypes());
        foreach ($deletions as $record) {
            $class = $em->getClassMetadata($record::class)->getName();
            $type = $this->commentTypes[$class] ?? null;
            $id = $em->getClassMetadata($class)->getIdentifierValues($record)['id'] ?? null;
            if ($type === null || $id === null) {
                continue;
            }
            foreach ($em->getRepository(Comment::class)->findBy(['entityType' => $type, 'entityId' => $id]) as $comment) {
                if (!$uow->isScheduledForDelete($comment)) {
                    $em->remove($comment);
                }
            }
        }
    }
}
