<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Control;
use App\Entity\Document;
use App\Entity\Risk;
use App\Entity\Supplier;
use App\Service\Review\PeriodicReviewService;
use App\Service\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Twig\Attribute\AsTwigFunction;

/**
 * `review_due_count('risk'|'control'|'document'|'supplier')` — number of the
 * current organisation's records whose periodic review is due (rule:
 * {@see PeriodicReviewService::isDue()}). Feeds the "Review fällig" chip of
 * the four lists that offer the bulk action "Review bestätigen".
 */
final class PeriodicReviewExtension
{
    private const array TYPES = [
        'risk' => Risk::class,
        'control' => Control::class,
        'document' => Document::class,
        'supplier' => Supplier::class,
    ];

    /** @var array<string, int> */
    private array $cache = [];

    public function __construct(
        private readonly PeriodicReviewService $periodicReviewService,
        private readonly EntityManagerInterface $entityManager,
        private readonly TenantContext $tenantContext,
    ) {
    }

    #[AsTwigFunction('review_due_count')]
    public function dueCount(string $type): int
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        $class = self::TYPES[$type] ?? null;
        if ($tenant === null || $class === null) {
            return 0;
        }
        $key = $type . ':' . $tenant->getId();

        return $this->cache[$key] ??= count($this->periodicReviewService->filterDue(
            $this->entityManager->getRepository($class)->findBy(['tenant' => $tenant]),
        ));
    }
}
