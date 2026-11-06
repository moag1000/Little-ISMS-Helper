<?php

declare(strict_types=1);

namespace App\Service\Risk;

use App\Entity\Risk;
use App\Entity\User;
use App\Enum\TreatmentStrategy;
use App\Repository\RiskRepository;
use App\Security\Voter\RiskAcceptanceVoter;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Requested risk acceptances waiting for this user's decision (ISO 27005
 * §8.4.4), as My Day items. Without this bucket a request went out by mail
 * only and the approver had no place in the app to decide it.
 */
class RiskAcceptanceInbox
{
    public function __construct(
        private readonly RiskRepository $risks,
        private readonly Security $security,
        private readonly RiskBusinessSummary $summary,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function pendingFor(User $user): array
    {
        $tenant = $user->getTenant();
        if ($tenant === null) {
            return [];
        }

        $items = [];
        foreach ($this->risks->findBy([
            'tenant' => $tenant,
            'treatmentStrategy' => TreatmentStrategy::Accept,
            'formallyAccepted' => false,
        ]) as $risk) {
            /** @var Risk $risk */
            if ($risk->getAcceptanceJustification() === null
                || !$this->security->isGranted(RiskAcceptanceVoter::APPROVE, $risk)) {
                continue;
            }
            $items[] = [
                'priority' => 'high',
                'due_date' => null,
                'link' => $this->urls->generate('app_risk_show', ['id' => $risk->getId()]) . '#acceptance',
                'entity_type' => 'risk_acceptance',
                'tone' => 'warning',
                'title' => (string) $risk->getTitle(),
                'subtitle' => $this->summary->line($risk),
                'badge' => null,
                'acceptance_risk_id' => $risk->getId(),
            ];
        }

        return $items;
    }
}
