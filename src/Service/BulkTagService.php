<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\EntityTag;
use App\Entity\Tag;
use App\Entity\User;
use App\Repository\EntityTagRepository;
use App\Repository\TagRepository;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\Proxy;
use InvalidArgumentException;

/**
 * Bulk tagging service (WS-5, DATA_REUSE_IMPROVEMENT_PLAN.md v1.1).
 *
 * Polymorphic contract per Anhang C ENT-1:
 *  - `applyTags`: add one or more tags to many entities at once (idempotent).
 *  - `removeTag`: soft-delete a single tag from a single entity (reason required).
 *  - `getActiveTagsFor` / `getHistoryFor`: read access for UI / audit.
 *
 * Every write produces an AuditLogger entry ("tag.applied", "tag.removed").
 */
class BulkTagService
{
    public const AUDIT_APPLIED = 'tag.applied';
    public const AUDIT_REMOVED = 'tag.removed';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TagRepository $tagRepository,
        private readonly EntityTagRepository $entityTagRepository,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    /**
     * Apply `$tagIds` to every entity in `$entities`. Re-adding an already-active
     * tag is skipped (idempotent). Returns counts per tag and overall.
     *
     * @param iterable<object> $entities
     * @param list<int>        $tagIds
     *
     * @return array{added: int, skipped: int, per_tag: array<int, array{added: int, skipped: int}>}
     */
    public function applyTags(iterable $entities, array $tagIds, User $actor): array
    {
        if ($tagIds === []) {
            return ['added' => 0, 'skipped' => 0, 'per_tag' => []];
        }

        $tags = $this->tagRepository->findBy(['id' => $tagIds]);
        if (count($tags) !== count(array_unique($tagIds))) {
            throw new InvalidArgumentException('One or more tag ids are invalid.');
        }

        $added = 0;
        $skipped = 0;
        $perTag = [];
        $appliedAt = new DateTimeImmutable();

        foreach ($entities as $entity) {
            $entityClass = $this->resolveEntityClass($entity);
            $entityId = $this->resolveEntityId($entity);
            if ($entityId === null) {
                $skipped++;
                continue;
            }

            foreach ($tags as $tag) {
                $tagId = (int) $tag->getId();
                $perTag[$tagId] ??= ['added' => 0, 'skipped' => 0];

                $existing = $this->entityTagRepository->findActiveOne($tag, $entityClass, $entityId);
                if ($existing instanceof EntityTag) {
                    $perTag[$tagId]['skipped']++;
                    $skipped++;
                    continue;
                }

                $link = (new EntityTag())
                    ->setTag($tag)
                    ->setEntityClass($entityClass)
                    ->setEntityId($entityId)
                    ->setTaggedBy($actor)
                    ->setTaggedFrom($appliedAt);

                $this->entityManager->persist($link);

                $perTag[$tagId]['added']++;
                $added++;
            }
        }

        $this->entityManager->flush();

        if ($added > 0) {
            $this->auditLogger->logCustom(
                self::AUDIT_APPLIED,
                'EntityTag',
                null,
                null,
                [
                    'actor_id' => $actor->getId(),
                    'tag_ids' => $tagIds,
                    'added' => $added,
                    'skipped' => $skipped,
                    'per_tag' => $perTag,
                ],
                sprintf('Bulk-tag applied: %d link(s) added, %d skipped', $added, $skipped),
                $actor->getEmail(),
            );
        }

        return [
            'added' => $added,
            'skipped' => $skipped,
            'per_tag' => $perTag,
        ];
    }

    /**
     * Soft-delete one active tag-link for a single entity. Keeps the row so the
     * audit-history stays intact (MINOR-2).
     */
    public function removeTag(
        Tag $tag,
        string $entityClass,
        int $entityId,
        User $actor,
        string $reason,
    ): void {
        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('Removal reason must not be empty.');
        }

        $link = $this->entityTagRepository->findActiveOne($tag, $entityClass, $entityId);
        if (!$link instanceof EntityTag) {
            // Nothing to remove — treat as no-op instead of error to keep bulk calls
            // tolerant, but skip the audit entry.
            return;
        }

        $link->setTaggedUntil(new DateTimeImmutable());
        $link->setRemovalReason($reason);

        $this->entityManager->flush();

        $this->auditLogger->logCustom(
            self::AUDIT_REMOVED,
            'EntityTag',
            $link->getId(),
            null,
            [
                'actor_id' => $actor->getId(),
                'tag_id' => $tag->getId(),
                'tag_name' => $tag->getName(),
                'entity_class' => $entityClass,
                'entity_id' => $entityId,
                'reason' => $reason,
            ],
            sprintf(
                'Tag "%s" removed from %s#%d',
                $tag->getName(),
                $this->shortClass($entityClass),
                $entityId,
            ),
            $actor->getEmail(),
        );
    }

    /**
     * @return Collection<int, EntityTag>
     */
    public function getActiveTagsFor(string $entityClass, int $entityId): Collection
    {
        return new ArrayCollection(
            $this->entityTagRepository->findActiveFor($entityClass, $entityId),
        );
    }

    /**
     * @return EntityTag[]
     */
    public function getHistoryFor(string $entityClass, int $entityId): array
    {
        return $this->entityTagRepository->findHistoryFor($entityClass, $entityId);
    }

    /**
     * Resolve the Doctrine "real" class name (strips lazy-loading proxy suffix).
     */
    private function resolveEntityClass(object $entity): string
    {
        if ($entity instanceof Proxy) {
            $parent = get_parent_class($entity);
            if ($parent !== false) {
                return $parent;
            }
        }

        return $entity::class;
    }

    private function resolveEntityId(object $entity): ?int
    {
        if (!method_exists($entity, 'getId')) {
            return null;
        }
        $id = $entity->getId();
        return is_int($id) ? $id : null;
    }

    private function shortClass(string $fqcn): string
    {
        $pos = strrpos($fqcn, '\\');
        return $pos === false ? $fqcn : substr($fqcn, $pos + 1);
    }
}
