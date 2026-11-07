<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\IsmsRegisterEntry;
use App\Repository\IsmsRegisterEntryRepository;
use App\Service\Register\RegisterLinkTypes;
use App\Service\TenantContext;
use Doctrine\Persistence\Proxy;
use Twig\Attribute\AsTwigFunction;

/**
 * Reverse view of the norm registers on a record's detail page: which legal
 * requirements, interfaces, communications or competences point at this
 * interested party, supplier or training. Nothing is stored twice; the link
 * lives on the register entry.
 */
final class RegisterExtension
{
    public function __construct(
        private readonly IsmsRegisterEntryRepository $entries,
        private readonly TenantContext $tenantContext,
    ) {
    }

    /** @var array<string, array{active: int, overdue: int, changed: ?\DateTimeImmutable}>|null */
    private ?array $stats = null;

    /**
     * Active entries and overdue reviews per register type of the current organisation.
     *
     * @return array<string, array{active: int, overdue: int, changed: ?\DateTimeImmutable}>
     */
    #[AsTwigFunction('register_stats')]
    public function stats(): array
    {
        $tenant = $this->tenantContext->getCurrentTenant();

        return $this->stats ??= $tenant === null ? [] : $this->entries->statsByType($tenant);
    }

    /** @return list<IsmsRegisterEntry> */
    #[AsTwigFunction('register_references')]
    public function references(object $record): array
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        $class = $record instanceof Proxy ? get_parent_class($record) : $record::class;
        $field = is_string($class) ? RegisterLinkTypes::fieldOfClass($class) : null;
        $id = method_exists($record, 'getId') ? $record->getId() : null;
        if ($tenant === null || $field === null || !is_int($id)) {
            return [];
        }

        return $this->entries->findLinkingTo($tenant, $field, $id);
    }
}
