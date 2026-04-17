<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Tag;
use App\Repository\TagRepository;
use App\Service\BulkTagService;
use App\Service\TenantContext;
use Twig\Attribute\AsTwigFunction;

/**
 * Twig glue for the polymorphic tag subsystem (WS-5).
 *
 * Exposes:
 *   available_tags()                          → Tag[] visible to current tenant
 *   active_tags_for(entityClass, entityId)    → EntityTag[] active links
 */
class TagExtension
{
    public function __construct(
        private readonly TagRepository $tagRepository,
        private readonly BulkTagService $bulkTagService,
        private readonly TenantContext $tenantContext,
    ) {
    }

    /**
     * @return Tag[]
     */
    #[AsTwigFunction('available_tags')]
    public function availableTags(): array
    {
        return $this->tagRepository->findVisibleFor($this->tenantContext->getCurrentTenant());
    }

    /**
     * @return iterable
     */
    #[AsTwigFunction('active_tags_for')]
    public function activeTagsFor(string $entityClass, int $entityId): iterable
    {
        return $this->bulkTagService->getActiveTagsFor($entityClass, $entityId);
    }
}
