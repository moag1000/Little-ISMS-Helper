<?php

declare(strict_types=1);

namespace App\Entity\Trait;

use App\Entity\Risk;
use App\Entity\RiskIncidentLink;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Attribute\MaxDepth;

/**
 * Risks of an incident — one list (P5): RiskIncidentLink. The former
 * ManyToMany "realizedRisks" (table incident_risk) became links of type
 * "materialized"; "realized" now means a link whose risk actually occurred
 * (materialized or a failed mitigation).
 */
trait IncidentRiskLinksTrait
{
    /**
     * @var Collection<int, RiskIncidentLink>
     */
    #[ORM\OneToMany(targetEntity: RiskIncidentLink::class, mappedBy: 'incident', cascade: ['persist'], orphanRemoval: true)]
    private Collection $riskLinks;

    /**
     * @return Collection<int, RiskIncidentLink>
     */
    public function getRiskLinks(): Collection
    {
        return $this->riskLinks;
    }

    /**
     * Every risk linked to the incident, whatever the link type.
     *
     * @return Collection<int, Risk>
     */
    public function getLinkedRisks(): Collection
    {
        return $this->risksOfLinks(static fn (RiskIncidentLink $l): bool => true);
    }

    /**
     * Risks that occurred in this incident (link type materialized or mitigation_failed).
     *
     * @return Collection<int, Risk>
     */
    #[Groups(['incident:read'])]
    #[MaxDepth(1)]
    public function getRealizedRisks(): Collection
    {
        return $this->risksOfLinks(static fn (RiskIncidentLink $l): bool => $l->isRealized());
    }

    /**
     * Links the risk as materialized; an existing link of another type is kept as it is.
     */
    public function addRealizedRisk(Risk $risk): static
    {
        if ($this->linkTo($risk) !== null) {
            return $this;
        }
        $link = new RiskIncidentLink();
        $link->setTenant($this->getTenant() ?? $risk->getTenant());
        $link->setRisk($risk);
        $link->setIncident($this);
        $link->setLinkType(RiskIncidentLink::TYPE_MATERIALIZED);
        $this->riskLinks->add($link);
        $risk->getIncidentLinks()->add($link);
        return $this;
    }

    public function removeRealizedRisk(Risk $risk): static
    {
        $link = $this->linkTo($risk);
        if ($link !== null) {
            $this->riskLinks->removeElement($link);
            $risk->getIncidentLinks()->removeElement($link);
        }
        return $this;
    }

    #[Groups(['incident:read'])]
    public function getRealizedRiskCount(): int
    {
        return $this->getRealizedRisks()->count();
    }

    /**
     * Data Reuse: the incident confirmed a previously identified risk.
     */
    #[Groups(['incident:read'])]
    public function isRiskValidated(): bool
    {
        return !$this->getRealizedRisks()->isEmpty();
    }

    private function linkTo(Risk $risk): ?RiskIncidentLink
    {
        foreach ($this->riskLinks as $link) {
            if ($link->getRisk() === $risk) {
                return $link;
            }
        }
        return null;
    }

    /**
     * @param callable(RiskIncidentLink): bool $keep
     * @return Collection<int, Risk>
     */
    private function risksOfLinks(callable $keep): Collection
    {
        $risks = [];
        foreach ($this->riskLinks as $link) {
            $risk = $link->getRisk();
            if ($risk !== null && $keep($link)) {
                $risks[spl_object_id($risk)] = $risk;
            }
        }
        return new ArrayCollection(array_values($risks));
    }
}
