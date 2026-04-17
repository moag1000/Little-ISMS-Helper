<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Tag;
use App\Repository\EntityTagRepository;
use App\Repository\TagRepository;

/**
 * Helper for `?tag=NIS2` filtering on index pages (WS-5).
 *
 * Resolves the tag name → Tag entity in the current tenant scope, fetches
 * the set of tagged entity IDs for the requested entity class, and returns
 * the subset of the input list whose IDs are in that set. Keeps controllers
 * slim (single call site each) and keeps the polymorphic contract internal.
 */
class TagFilterService
{
    public function __construct(
        private readonly TagRepository $tagRepository,
        private readonly EntityTagRepository $entityTagRepository,
        private readonly TenantContext $tenantContext,
    ) {
    }

    /**
     * Filter `$entities` to those currently tagged with `$tagName`. Passes
     * through unchanged when `$tagName` is null/empty or the tag doesn't exist.
     *
     * @template T of object
     * @param iterable<T> $entities
     * @param class-string $entityClass
     *
     * @return list<T>
     */
    public function filterByTagName(iterable $entities, string $entityClass, ?string $tagName): array
    {
        $list = is_array($entities) ? array_values($entities) : iterator_to_array($entities, false);

        $tagName = $tagName !== null ? trim($tagName) : '';
        if ($tagName === '') {
            return $list;
        }

        $tag = $this->resolveTag($tagName);
        if (!$tag instanceof Tag) {
            return [];
        }

        $activeIds = array_flip($this->entityTagRepository->findEntityIdsWithTag($tag, $entityClass));

        return array_values(array_filter(
            $list,
            static function (object $entity) use ($activeIds): bool {
                if (!method_exists($entity, 'getId')) {
                    return false;
                }
                $id = $entity->getId();
                return is_int($id) && isset($activeIds[$id]);
            },
        ));
    }

    private function resolveTag(string $name): ?Tag
    {
        $tenant = $this->tenantContext->getCurrentTenant();

        $tag = $this->tagRepository->findOneByName($tenant, $name);
        if ($tag instanceof Tag) {
            return $tag;
        }

        // Fall back to global tag (tenant IS NULL)
        return $this->tagRepository->findOneByName(null, $name);
    }
}
