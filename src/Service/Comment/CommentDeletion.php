<?php

declare(strict_types=1);

namespace App\Service\Comment;

use App\Entity\Comment;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;

/**
 * S29 (GDPR Art. 17, ISO 27001 7.5.3): removing one comment, by an admin, with
 * a reason. The trail keeps who, when, whose comment on which record and why —
 * never the removed text. Comments of a deleted record go with the record
 * ({@see \App\EventListener\DeletionGuard}).
 */
final readonly class CommentDeletion
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private AuditLogger $auditLogger,
    ) {
    }

    public function delete(Comment $comment, string $reason): void
    {
        $reason = mb_substr(trim($reason), 0, 500);
        $values = [
            'comment_id' => $comment->getId(),
            'author_id' => $comment->getAuthor()?->getId(),
            'created_at' => $comment->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'reason' => $reason,
        ];
        $entityType = $comment->getEntityType();
        $entityId = $comment->getEntityId();

        $this->entityManager->remove($comment);
        $this->auditLogger->beginCallerLoggedDeletes(); // comment_deleted below, with the reason
        try {
            $this->entityManager->flush();
        } finally {
            $this->auditLogger->endCallerLoggedDeletes();
        }
        $this->auditLogger->logCustom(
            action: 'comment_deleted',
            entityType: $entityType,
            entityId: $entityId,
            oldValues: $values,
            description: sprintf('Comment on %s#%d removed: %s', $entityType, $entityId, $reason),
        );
    }
}
