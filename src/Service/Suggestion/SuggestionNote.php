<?php

declare(strict_types=1);

namespace App\Service\Suggestion;

use App\Entity\Comment;
use App\Entity\Tenant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * An accepted AI text that is a note, not a field value (audit note on a
 * control, explanation on a risk): it becomes a comment on the record, by the
 * person who accepted it. `entityType` is the short name the comment thread
 * of the detail page uses (see CommentController).
 */
final readonly class SuggestionNote
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function add(Tenant $tenant, string $entityType, int $entityId, User $by, string $body): Comment
    {
        $comment = (new Comment())
            ->setTenant($tenant)
            ->setEntityType($entityType)
            ->setEntityId($entityId)
            ->setAuthor($by)
            ->setBody($body);
        $this->entityManager->persist($comment);
        $this->entityManager->flush();

        return $comment;
    }
}
