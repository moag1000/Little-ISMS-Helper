<?php

declare(strict_types=1);

namespace App\Entity\Trait;

use App\Entity\DoraSubcontractor;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Subcontractors of a supplier (DORA Art. 28 (3), Art. 30 (2) a) — one list (P5):
 * the DoraSubcontractor rows. The former JSON column subcontractorChain was
 * migrated into them; getSubcontractorChain() keeps its array shape for the
 * readers (DPA annex, location chains, RoI CSV, detail tree).
 */
trait SupplierSubcontractorsTrait
{
    /**
     * @var Collection<int, DoraSubcontractor>
     */
    #[ORM\OneToMany(targetEntity: DoraSubcontractor::class, mappedBy: 'parentSupplier')]
    #[ORM\OrderBy(['tier' => \SortDirection::Ascending, 'name' => \SortDirection::Ascending])]
    private Collection $subcontractors;

    /**
     * @return Collection<int, DoraSubcontractor>
     */
    public function getSubcontractors(): Collection
    {
        return $this->subcontractors;
    }

    /**
     * The chain as a depth-first list, each node after its parent.
     *
     * @return list<array{id: int|null, tier: int, name: string, lei: string|null, country: string|null,
     *     service: string|null, criticality: string, critical: bool}>
     */
    public function getSubcontractorChain(): array
    {
        $rows = [];
        $walk = static function (DoraSubcontractor $node, int $depth) use (&$walk, &$rows): void {
            if ($depth > 10) {
                return;
            }
            $rows[] = [
                'id' => $node->getId(),
                'tier' => max(1, $node->getTier() - 1), // 1 = direct subcontractor (DB tier 2)
                'name' => (string) $node->getName(),
                'lei' => $node->getLeiCode(),
                'country' => $node->getCountry(),
                'service' => $node->getServiceDescription(),
                'criticality' => $node->getCriticality(),
                'critical' => $node->getCriticality() === 'critical',
            ];
            foreach ($node->getChildren() as $child) {
                $walk($child, $depth + 1);
            }
        };
        foreach ($this->subcontractors as $node) {
            if ($node->getParentSubcontractor() === null) {
                $walk($node, 0);
            }
        }
        return $rows;
    }
}
